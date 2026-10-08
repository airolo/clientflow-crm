<?php
/**
 * CSV export.
 *
 * Deliberately not routed through list_query(). That builder caps a page at 100
 * rows, and an export has to include every row the workspace owns - a silently
 * truncated export is worse than no export, because nobody checks the row count
 * of a file they asked to be complete. So each model has its own unpaginated,
 * still tenant-scoped, row generator.
 *
 * Nothing here includes views/header.php. An export is a file download, so the
 * page must emit headers and bytes and nothing else.
 */

declare(strict_types=1);

/**
 * Make one value safe to put in a CSV cell.
 *
 * The dangerous part of CSV is not the commas, it is the leading characters a
 * spreadsheet treats as the start of a formula. A client named
 * `=HYPERLINK("http://evil","click")` becomes a live link the moment someone
 * opens the export in Excel, and a company name is attacker-influenced data
 * precisely because anyone in the sales team can type one.
 *
 * Prefixing with an apostrophe is the standard fix: Excel and LibreOffice both
 * treat the cell as text, and the apostrophe is not displayed.
 *
 * The check trims first. Excel ignores leading whitespace before deciding a
 * cell is a formula, so ` =1+1` is just as dangerous as `=1+1`, and testing the
 * raw string would pass it straight through.
 */
function csv_formula_safe(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }

    $string = trim((string) $value);
    if ($string === '') {
        return '';
    }

    // Tab and carriage return are included because they are stripped or treated
    // as whitespace by some readers, which lets them smuggle a leading = past a
    // naive check.
    $first = $string[0];
    if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $string;
    }

    return $string;
}

/**
 * Start a CSV download.
 *
 * Discards any buffered output first. bootstrap.php starts a session, and a
 * stray newline or BOM before the headers would produce a file Excel refuses to
 * open - or worse, one it opens with the first row already corrupted.
 */
function csv_send_headers(string $filename): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Headers that stop a browser treating the response as HTML.
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/**
 * Build a download filename that says which workspace and when.
 *
 * The workspace slug is in the name on purpose. Someone exporting from three
 * customer sites ends up with `clients.csv` three times over and no way to tell
 * them apart; `clientflow-demo-clients-2026-10-08.csv` cannot be confused with
 * anything.
 *
 * The slug is from the database and the date is generated, so neither can carry
 * a quote or newline - but the value is still reduced to a safe character set
 * before being placed in a quoted header, because a malformed
 * Content-Disposition is header injection.
 */
function csv_export_filename(string $kind): string
{
    $slug = preg_replace('/[^a-z0-9-]+/i', '-', (string) (tenant_slug() ?? 'workspace')) ?? 'workspace';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'workspace';
    }

    $kind = preg_replace('/[^a-z]+/i', '', $kind) ?: 'export';

    return 'clientflow-' . $slug . '-' . $kind . '-' . date('Y-m-d') . '.csv';
}

/**
 * Write the header row.
 *
 * $handle defaults to the download stream. The workspace bundle passes a
 * temporary file handle instead, so each CSV is built somewhere else before
 * being added to the zip - writing them to php://output and then zipping the
 * temp files produced an archive with empty members and a response whose body
 * had already been committed.
 */
function csv_emit_header(array $headers, $handle = null): void
{
    fputcsv($handle ?? csv_handle(), array_map('csv_formula_safe', $headers));
}

/** Write one data row. */
function csv_emit_row(array $values, $handle = null): void
{
    fputcsv($handle ?? csv_handle(), array_map('csv_formula_safe', $values));
}

/**
 * The shared output handle, opened once.
 *
 * Held in a static so fputcsv is never called before csv_send_headers(), which
 * is what would otherwise produce a file whose first bytes are HTML.
 */
function csv_handle()
{
    static $handle = null;
    if ($handle === null) {
        $handle = fopen('php://output', 'w');
    }
    return $handle;
}
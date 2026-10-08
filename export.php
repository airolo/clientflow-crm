<?php
/**
 * CSV export (any signed-in user).
 *
 * One endpoint for every record type, chosen with `?type=`. The type is resolved
 * through a fixed map rather than used to build a function name, so a crafted
 * value cannot reach anything outside the five exports offered here.
 *
 * This page deliberately does not include views/header.php. It is a file
 * download, so it emits headers and bytes and nothing else; a stray newline
 * before the headers is enough to make Excel refuse the file.
 */

declare(strict_types=1);

// This page sits at the project root, not in a feature folder, so the path to
// app/ has no "../" - which is exactly the kind of thing that is easy to copy
// from a sibling page and miss.
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/csv.php';
require_login();

/**
 * The exports this page offers, each declaring its own columns.
 *
 * Column order and the row generator live together on purpose: adding a column
 * to one and forgetting the other is otherwise a silent misalignment, where the
 * file downloads fine and every value sits under the wrong heading.
 */
function csv_exports(): array
{
    return [
        'client' => [
            'label' => 'Clients',
            'columns' => ['Company', 'Contact', 'Email', 'Phone', 'Address', 'Status', 'Assigned to', 'Notes', 'Created'],
            'rows' => 'client_export_rows',
            'keys'  => ['company_name', 'contact_person', 'email', 'phone', 'address', 'status', 'owner_name', 'notes', 'created_at'],
        ],
        'lead' => [
            'label' => 'Leads',
            'columns' => ['Name', 'Company', 'Email', 'Phone', 'Source', 'Status', 'Estimated value', 'Assigned to', 'Notes', 'Created'],
            'rows' => 'lead_export_rows',
            'keys'  => ['lead_name', 'company', 'email', 'phone', 'lead_source', 'status', 'estimated_value', 'owner_name', 'notes', 'created_at'],
        ],
        'deal' => [
            'label' => 'Deals',
            'columns' => ['Deal', 'Client', 'Lead', 'Stage', 'Value', 'Expected close', 'Assigned to', 'Notes', 'Created'],
            'rows' => 'deal_export_rows',
            'keys'  => ['deal_title', 'client_name', 'lead_name', 'stage', 'value', 'expected_close_date', 'owner_name', 'notes', 'created_at'],
        ],
        'task' => [
            'label' => 'Tasks',
            'columns' => ['Title', 'Description', 'Client', 'Lead', 'Status', 'Priority', 'Due', 'Completed', 'Assigned to', 'Created'],
            'rows' => 'task_export_rows',
            'keys'  => ['title', 'description', 'client_name', 'lead_name', 'status', 'priority', 'due_date', 'completed_at', 'owner_name', 'created_at'],
        ],
        'activity' => [
            'label' => 'Activities',
            'columns' => ['Type', 'Title', 'Details', 'Client', 'Lead', 'Logged by', 'When'],
            'rows' => 'activity_export_rows',
            'keys'  => ['type', 'title', 'details', 'client_name', 'lead_name', 'owner_name', 'created_at'],
        ],
    ];
}

/**
 * Turn one database row into display-ready CSV cells.
 *
 * Readable values rather than raw enum keys. A downloaded file is often passed
 * on to someone outside the app, where "won" means nothing and "Won" does, and
 * 24000 in a cell is ambiguous about both scale and currency.
 *
 * A named function rather than a closure so the single-list exports and the
 * workspace bundle format their rows identically - two copies of this would
 * drift, and a bundle whose files disagreed with the individual exports would be
 * worse than no bundle.
 */
function export_readable_row(array $row, array $keys): array
{
    $out = [];
    foreach ($keys as $key) {
        $value = $row[$key] ?? '';

        if (in_array($key, ['value', 'estimated_value'], true) && $value !== '' && $value !== null) {
            $value = money($value);
        }
        if ($key === 'status' && $value !== '') {
            $value = pretty((string) $value);
        }
        if ($key === 'stage' && $value !== '') {
            $value = pretty(str_replace('_', ' ', (string) $value));
        }
        if ($key === 'type' && $value !== '') {
            $value = pretty((string) $value);
        }
        if ($key === 'priority' && $value !== '') {
            $value = ucfirst((string) $value);
        }
        if ($key === 'created_at' && $value !== '') {
            $value = nice_date((string) $value);
        }

        $out[] = $value;
    }
    return $out;
}

/**
 * The whole workspace as a zip of the five CSVs, plus a manifest.
 *
 * Written to a temporary file and streamed, never assembled in memory: a large
 * workspace would otherwise be held twice, once as row arrays and once as the
 * zip. The temp file is deleted afterwards whatever happens.
 *
 * The manifest is deliberately plain text naming the workspace, when it was
 * taken, and how many rows each file holds. Someone handed this zip years later
 * needs to know what it is and whether it is complete - that is the difference
 * between a data copy and an unlabelled folder of CSVs.
 */
function export_bundle(): void
{
    $exports = csv_exports();

    if (!class_exists('ZipArchive')) {
        flash_error('This server cannot build zip files, so a bundle is not available. Export the five lists individually instead.', 'dashboard.php');
    }

    $temp = tempnam(sys_get_temp_dir(), 'cfbundle');
    if ($temp === false) {
        flash_error('Could not create a temporary file for the bundle.', 'dashboard.php');
    }

    try {
        $zip = new ZipArchive();
        if ($zip->open($temp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('could not open the archive');
        }

        $counts = [];
        // Collected and removed in the finally block, not straight after
        // addFile(): ZipArchive reads its members when close() finalises the
        // archive, so deleting them early leaves it with nothing to write.
        $temps = [];

        foreach ($exports as $kind => $export) {
            $path = tempnam(sys_get_temp_dir(), 'cfcsv');
            if ($path === false) {
                throw new RuntimeException('could not make a temporary file for ' . $kind);
            }
            $temps[] = $path;

            $handle = fopen($path, 'wb');
            if ($handle === false) {
                throw new RuntimeException('could not open a temporary file for ' . $kind);
            }

            // The handle is passed through so these rows land in the temp file
            // rather than in the response body.
            csv_emit_header($export['columns'], $handle);
            $rows = 0;
            foreach (($export['rows'])() as $row) {
                csv_emit_row(export_readable_row($row, $export['keys']), $handle);
                $rows++;
            }
            fclose($handle);

            $counts[$export['label']] = $rows;
            $zip->addFile($path, $export['label'] . '.csv');
        }

        $slug = (string) (tenant_slug() ?? 'workspace');
        $name = (string) (tenant_current()['name'] ?? 'workspace');
        $manifest = "ClientFlow workspace export\n"
            . "=========================\n\n"
            . 'Workspace : ' . $name . "\n"
            . 'Sign-in   : ' . $slug . "\n"
            . 'Exported  : ' . date('Y-m-d H:i:s T') . "\n"
            . 'Currency  : ' . tenant_currency() . "\n"
            . 'Timezone  : ' . date_default_timezone_get() . "\n\n"
            . "Rows per file\n"
            . "-------------\n";
        foreach ($counts as $label => $n) {
            $manifest .= sprintf("  %-12s %d\n", $label, $n);
        }
        $manifest .= "\nEach file is a CSV with one header row. Values that begin with =, +, - or @\n"
            . "are prefixed with an apostrophe so a spreadsheet treats them as text; strip the\n"
            . "leading apostrophe if you re-import somewhere that does not need it.\n\n"
            . "This is a copy of your data, not an application backup. It cannot be restored\n"
            . "into a running ClientFlow install - import.php reads the file names above, and\n"
            . "tools\\restore.ps1 is what restores a database.\n";

        $zip->addFromString('README.txt', $manifest);
        if (!$zip->close()) {
            throw new RuntimeException('the archive could not be finalised');
        }
        clearstatcache();
        if (!is_file($temp) || filesize($temp) < 22) {
            // A zip's minimum is an end-of-central-directory record alone. An
            // earlier version shipped whatever was in the file regardless, which
            // produced a download with a correct application/zip content type
            // and a body of zero bytes - the sort of failure that looks like the
            // user's browser is at fault.
            throw new RuntimeException('the archive is empty (' . (is_file($temp) ? filesize($temp) : 'no file') . ' bytes)');
        }

        // The zip's own headers, not csv_send_headers(): this is a zip, not a
        // CSV, and only the filename logic is shared.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="clientflow-' . $slug . '-workspace-' . date('Y-m-d') . '.zip"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        readfile($temp);
    } catch (Throwable $e) {
        error_log('ClientFlow bundle export failed: ' . $e->getMessage());
        if (!headers_sent()) {
            http_response_code(500);
            echo 'The bundle could not be built.';
        }
    } finally {
        // Temp files only. Deliberately no ob_end_clean() here: the zip bytes are
        // sitting in the output buffer at this point, and discarding the buffer
        // delivers an empty file with a valid zip content type - which is what
        // an earlier version did.
        foreach ($temps ?? [] as $t) {
            @unlink($t);
        }
        @unlink($temp);
    }
}

// is_string() first: ?type[]=client makes $_GET['type'] an array, and casting
// that to a string logs "Array to string conversion" before the lookup below
// rejects it anyway.
$raw  = $_GET['type'] ?? null;
$type = is_string($raw) ? $raw : '';
$all   = csv_exports();
$valid = $type === 'bundle' || array_key_exists($type, $all);

// A bundle is the whole workspace as a zip of the same five files, for taking
// your data somewhere else. It is not a backup you can restore into a running
// app - tools\restore.ps1 is that - it is a portable copy in a format a person
// can read.
if ($type === 'bundle') {
    export_bundle();
    exit;
}

if (!$valid) {
    flash_error('That is not something this page can export.', 'dashboard.php');
}

$export = $all[$type];

csv_send_headers(csv_export_filename($type));
csv_emit_header($export['columns']);

$count = 0;
foreach (($export['rows'])() as $row) {
    csv_emit_row(export_readable_row($row, $export['keys']));
    $count++;
}

// $count is deliberately not echoed. This is a file response; appending a summary
// would be parsed as one more malformed row.

exit;
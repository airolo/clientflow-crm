<?php
/**
 * Shared helper functions used across the whole application.
 */

declare(strict_types=1);

/**
 * Escape a value for safe output in HTML. Use this on EVERY dynamic value.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Read a trimmed string from $_POST. */
function post_str(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

/** Read an int from $_POST (null when empty/invalid). */
function post_int(string $key): ?int
{
    $value = $_POST[$key] ?? '';
    return ($value === '' || !is_numeric($value)) ? null : (int) $value;
}

/** Read a float from $_POST (0 when empty/invalid). */
function post_float(string $key): float
{
    $value = $_POST[$key] ?? '';
    return is_numeric($value) ? (float) $value : 0.0;
}

/** Turn an empty string into null so nullable columns stay NULL. */
function null_if_empty(?string $value): ?string
{
    $value = $value === null ? null : trim($value);
    return ($value === null || $value === '') ? null : $value;
}

/** True when the value is inside the given list of allowed options. */
function is_valid_option(?string $value, array $allowed): bool
{
    return $value !== null && in_array($value, $allowed, true);
}

/** Send the user to another page and stop. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Current page filename, used to mark the active sidebar link. */
function current_page(): string
{
    return basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
}

// ---------------------------------------------------------------------------
// Flash messages (one request only)
// ---------------------------------------------------------------------------

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** Pull and clear the queued flash messages. */
function take_flashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $flashes;
}

/** Queue a success message and redirect. */
function flash_success(string $message, string $redirectTo): void
{
    flash('success', $message);
    redirect($redirectTo);
}

/** Queue an error message and redirect. */
function flash_error(string $message, string $redirectTo): void
{
    flash('danger', $message);
    redirect($redirectTo);
}

/** Store form errors + submitted values, then bounce back to the form. */
function redirect_with_errors(string $path, array $errors, array $old = []): void
{
    $_SESSION['_errors'] = $errors;
    $_SESSION['_old'] = $old;
    redirect($path);
}

/** Read (and clear) validation errors from the previous request. */
function take_errors(): array
{
    $errors = $_SESSION['_errors'] ?? [];
    unset($_SESSION['_errors']);
    return $errors;
}

/** Read (and clear) previously submitted values. */
function take_old(): array
{
    $old = $_SESSION['_old'] ?? [];
    unset($_SESSION['_old']);
    return $old;
}

/**
 * Value helper for forms: prefers old input, falls back to the database record,
 * then a default.
 */
function old_value(array $old, array $record, string $key, string $default = ''): string
{
    if (array_key_exists($key, $old)) {
        return (string) $old[$key];
    }
    if (array_key_exists($key, $record)) {
        return (string) ($record[$key] ?? $default);
    }
    return $default;
}

/** First validation error message for a field, or null. */
function field_error(array $errors, string $field): ?string
{
    return $errors[$field] ?? null;
}

/** Adds the `is-invalid` class when a field has an error. */
function is_invalid(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' is-invalid' : '';
}

/**
 * Length checks matching the database column widths.
 *
 * The HTML maxlength attribute is a convenience, not a control: a direct POST
 * bypasses it entirely, and MySQL then rejects the insert with an uncaught
 * exception. Every VARCHAR column therefore needs a matching server-side
 * limit, and this helper keeps them in one readable place.
 *
 * @param array $specs field name => [value, maxLength, human label]
 * @return array field name => error message (only fields that are too long)
 */
function length_errors(array $specs): array
{
    $errors = [];
    foreach ($specs as $field => [$value, $max, $label]) {
        $length = mb_strlen((string) ($value ?? ''));
        if ($length > $max) {
            $errors[$field] = sprintf(
                '%s is too long (%d characters, maximum %d).',
                $label,
                $length,
                $max
            );
        }
    }
    return $errors;
}

// ---------------------------------------------------------------------------
// CSRF protection
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** Hidden input to drop inside every <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

/**
 * Abort the request unless the submitted token matches the session token.
 *
 * Uses 403 rather than the "419 Page Expired" convention: Apache does not
 * recognise 419 and rewrites it to a generic 500, which hides the real cause.
 */
function verify_csrf(): void
{
    $token = $_POST['_token'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Request blocked for security reasons.\n\n"
            . "Your form session expired or the security token was missing, so nothing was saved.\n"
            . "Please go back, reload the page and try again.");
    }
}

// ---------------------------------------------------------------------------
// Formatting helpers
// ---------------------------------------------------------------------------

/** Format a money amount, e.g. 24000 -> £24,000. */
function money(float|int|string|null $amount): string
{
    return '£' . number_format((float) $amount, 2);
}

/** Compact money for dashboard tiles: 125000 -> £125k. */
function money_short(float|int|string|null $amount): string
{
    $amount = (float) $amount;
    if ($amount >= 1000000) {
        return '£' . number_format($amount / 1000000, 1) . 'm';
    }
    if ($amount >= 1000) {
        return '£' . number_format($amount / 1000, 0) . 'k';
    }
    return '£' . number_format($amount, 0);
}

/** Format a date for display: 2026-10-06 -> 06 Oct 2026. */
function nice_date(?string $date): string
{
    if (!$date || str_starts_with($date, '0000')) {
        return '—';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date('d M Y', $timestamp) : '—';
}

/** Relative time for the activity feed: "3 hours ago". */
function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return '';
    }
    $seconds = time() - $timestamp;

    if ($seconds < 60) {
        return 'just now';
    }
    $units = [
        31536000 => 'year',
        2592000  => 'month',
        604800   => 'week',
        86400    => 'day',
        3600     => 'hour',
        60       => 'minute',
    ];
    foreach ($units as $length => $unit) {
        if ($seconds >= $length) {
            return floor($seconds / $length) . ' ' . $unit . ($seconds / $length >= 2 ? 's' : '') . ' ago';
        }
    }
    return 'just now';
}

/** Human label + colour for a client status. */
function client_status_badge(?string $status): string
{
    $map = [
        'active'    => ['Active', 'success'],
        'prospect'  => ['Prospect', 'info'],
        'inactive'  => ['Inactive', 'secondary'],
    ];
    [$label, $colour] = $map[$status] ?? ['Unknown', 'secondary'];
    return '<span class="badge text-bg-' . $colour . '">' . e($label) . '</span>';
}

/** Human label + colour for a lead status. */
function lead_status_badge(?string $status): string
{
    $map = [
        'new'         => ['New', 'secondary'],
        'contacted'   => ['Contacted', 'info'],
        'qualified'   => ['Qualified', 'primary'],
        'proposal'    => ['Proposal', 'warning'],
        'negotiation' => ['Negotiation', 'danger'],
        'won'         => ['Won', 'success'],
        'lost'        => ['Lost', 'dark'],
    ];
    [$label, $colour] = $map[$status] ?? ['Unknown', 'secondary'];
    return '<span class="badge text-bg-' . $colour . '">' . e($label) . '</span>';
}

/** Human label + colour for a deal stage. */
function stage_badge(?string $stage): string
{
    $map = [
        'new_lead'    => ['New Lead', 'secondary'],
        'contacted'   => ['Contacted', 'info'],
        'proposal'    => ['Proposal', 'warning'],
        'negotiation' => ['Negotiation', 'danger'],
        'won'         => ['Won', 'success'],
        'lost'        => ['Lost', 'dark'],
    ];
    [$label, $colour] = $map[$stage] ?? ['Unknown', 'secondary'];
    return '<span class="badge text-bg-' . $colour . '">' . e($label) . '</span>';
}

/** Human label + colour for a task priority. */
function priority_badge(?string $priority): string
{
    $map = [
        'high'   => ['High', 'danger'],
        'medium' => ['Medium', 'warning'],
        'low'    => ['Low', 'secondary'],
    ];
    [$label, $colour] = $map[$priority] ?? ['Medium', 'secondary'];
    return '<span class="badge text-bg-' . $colour . '">' . e($label) . '</span>';
}

/** Human label + colour for a task status. */
function task_status_badge(?string $status): string
{
    $map = [
        'pending'     => ['Pending', 'warning'],
        'in_progress' => ['In Progress', 'info'],
        'completed'   => ['Completed', 'success'],
    ];
    [$label, $colour] = $map[$status] ?? ['Pending', 'secondary'];
    return '<span class="badge text-bg-' . $colour . '">' . e($label) . '</span>';
}

/** Human label + icon name for an activity type. */
function activity_icon(?string $type): array
{
    $map = [
        'call'    => ['Call', 'bi-telephone-fill'],
        'email'   => ['Email', 'bi-envelope-fill'],
        'meeting' => ['Meeting', 'bi-calendar-event-fill'],
        'note'    => ['Note', 'bi-journal-text'],
    ];
    return $map[$type] ?? ['Update', 'bi-info-circle-fill'];
}

// ---------------------------------------------------------------------------
// Lists used by filters and dropdowns
// ---------------------------------------------------------------------------

function client_statuses(): array
{
    return ['prospect', 'active', 'inactive'];
}

function lead_statuses(): array
{
    return ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];
}

function lead_sources(): array
{
    return ['website', 'referral', 'cold_call', 'email_campaign', 'social_media', 'event', 'other'];
}

function deal_stages(): array
{
    return ['new_lead', 'contacted', 'proposal', 'negotiation', 'won', 'lost'];
}

function open_deal_stages(): array
{
    return ['new_lead', 'contacted', 'proposal', 'negotiation'];
}

function task_priorities(): array
{
    return ['low', 'medium', 'high'];
}

function task_statuses(): array
{
    return ['pending', 'in_progress', 'completed'];
}

function activity_types(): array
{
    return ['call', 'email', 'meeting', 'note'];
}

/** Pretty-print an enum value: cold_call -> Cold call. */
function pretty(string $value): string
{
    return ucfirst(str_replace('_', ' ', $value));
}

/** Nicely format a list of enum values for a <select>. */
function select_options(array $values, string|int|null $selected = null): string
{
    $html = '';
    foreach ($values as $value) {
        $html .= '<option value="' . e((string) $value) . '"'
            . ((string) $value === (string) $selected ? ' selected' : '') . '>'
            . e(pretty((string) $value)) . '</option>';
    }
    return $html;
}

// ---------------------------------------------------------------------------
// Query-string helpers (search / filter / sort / pagination)
// ---------------------------------------------------------------------------

/** Build a URL preserving current filters but overriding some values. */
function url_with(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    $query['page'] = $query['page'] ?? 1;
    return '?' . http_build_query($query);
}

/** Whitelist-checked ORDER BY so sort links can never inject SQL. */
function order_by(array $allowed, string $default): string
{
    $column = $_GET['sort'] ?? '';
    $direction = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    if (!is_string($column) || !isset($allowed[$column])) {
        $column = $default;
    }
    return $allowed[$column] . ' ' . $direction;
}

/**
 * The direction a sortable column should switch to when clicked, and the URL
 * that does it. Keeps the toggle logic in one place.
 */
function sort_href(string $column): string
{
    $active = ($_GET['sort'] ?? '') === $column;
    $nextDir = ($active && strtolower((string) ($_GET['dir'] ?? 'asc')) === 'asc') ? 'desc' : 'asc';
    return url_with(['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
}

/**
 * Render a sortable column header link. Still used where a full header cell
 * is written inline; render_th() in list_page.php is preferred.
 */
function sort_link(string $label, string $column, array $allowed, string $default): string
{
    $active = ($_GET['sort'] ?? '') === $column;
    $nextDir = ($active && strtolower((string) ($_GET['dir'] ?? 'asc')) === 'asc') ? 'desc' : 'asc';
    $icon = $active
        ? '<i class="bi bi-caret-' . ($nextDir === 'asc' ? 'up' : 'down') . '-fill small"></i>'
        : '';
    return '<a href="' . e(url_with(['sort' => $column, 'dir' => $nextDir, 'page' => 1]))
        . '" class="text-decoration-none text-reset">' . e($label) . ' ' . $icon . '</a>';
}

/** Clamp the requested page number into range. */
function current_page_number(): int
{
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    return max(1, $page);
}

/**
 * Render Bootstrap pagination for a result set.
 */
function render_pagination(int $total, int $perPage = ROWS_PER_PAGE): string
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = current_page_number();
    if ($pages <= 1) {
        return '';
    }

    $start = max(1, $page - 2);
    $end = min($pages, $start + 4);
    $start = max(1, $end - 4);

    $html = '<nav aria-label="Result pages"><ul class="pagination pagination-sm mb-0">';
    $html .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '">'
        . '<a class="page-link" href="' . e(url_with(['page' => $page - 1])) . '">&laquo;</a></li>';

    for ($i = $start; $i <= $end; $i++) {
        $html .= '<li class="page-item' . ($i === $page ? ' active' : '') . '">'
            . '<a class="page-link" href="' . e(url_with(['page' => $i])) . '">' . $i . '</a></li>';
    }

    $html .= '<li class="page-item' . ($page >= $pages ? ' disabled' : '') . '">'
        . '<a class="page-link" href="' . e(url_with(['page' => $page + 1])) . '">&raquo;</a></li>';
    $html .= '</ul></nav>';

    return $html;
}

/** "Showing X to Y of Z" summary shown under a table. */
function result_summary(int $total, int $offset, int $count): string
{
    if ($total === 0) {
        return '';
    }
    $from = $offset + 1;
    $to = $offset + $count;
    return 'Showing <strong>' . $from . '</strong>–<strong>' . $to . '</strong> of <strong>'
        . $total . '</strong> record' . ($total === 1 ? '' : 's');
}

/**
 * Empty-state block used by every list page.
 */
function empty_state(string $icon, string $title, string $message, ?string $actionUrl = null, string $actionLabel = ''): string
{
    $html = '<div class="empty-state text-center py-5">'
        . '<div class="empty-state-icon mb-3"><i class="bi ' . e($icon) . '"></i></div>'
        . '<h5 class="mb-1">' . e($title) . '</h5>'
        . '<p class="text-secondary mb-3">' . e($message) . '</p>';
    if ($actionUrl) {
        $html .= '<a href="' . e($actionUrl) . '" class="btn btn-primary">'
            . '<i class="bi bi-plus-lg me-1"></i>' . e($actionLabel) . '</a>';
    }
    return $html . '</div>';
}

/** Initials for an avatar bubble, e.g. "Sarah Bennett" -> "SB". */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/** Deterministic pleasant colour for an avatar bubble. */
function avatar_colour(string $name): string
{
    $palette = ['#4f46e5', '#0ea5e9', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777', '#0891b2'];
    return $palette[abs(crc32($name)) % count($palette)];
}
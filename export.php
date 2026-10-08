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

$type  = (string) ($_GET['type'] ?? '');
$all   = csv_exports();
$valid = array_key_exists($type, $all);

if (!$valid) {
    flash_error('That is not something this page can export.', 'dashboard.php');
}

$export = $all[$type];

// Readable values rather than raw enum keys. A downloaded file is often passed
// on to someone outside the app, where "won" means nothing and "Won" does.
$readable = static function (array $row, array $keys, string $type): array {
    $out = [];
    foreach ($keys as $key) {
        $value = $row[$key] ?? '';

        // Money columns are formatted rather than dumped as raw floats: 24000
        // in a cell is ambiguous about both scale and currency.
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
};

csv_send_headers(csv_export_filename($type));
csv_emit_header($export['columns']);

$count = 0;
foreach (($export['rows'])() as $row) {
    csv_emit_row($readable($row, $export['keys'], $type));
    $count++;
}

// $count is deliberately not echoed. This is a file response; appending a summary
// would be parsed as one more malformed row.

exit;
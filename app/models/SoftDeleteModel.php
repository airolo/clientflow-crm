<?php
/**
 * Soft delete - the shared behaviour behind "delete" and "restore".
 *
 * Deleting used to issue a hard DELETE. On clients that cascaded to deals,
 * tasks and the whole activity history, so one click destroyed data that
 * could not be recovered and left no record that it had happened.
 *
 * Now a delete stamps deleted_at/deleted_by and the row is merely hidden.
 * Restoring is the same UPDATE with the two columns cleared, which is why
 * restoring a client brings its deals, tasks and activities straight back:
 * they were never touched.
 *
 * Child visibility is decided in SQL rather than by cascading the stamp, so a
 * restore cannot half-succeed - there is only ever one row to change.
 */

declare(strict_types=1);

/**
 * Tables that support soft delete, and how to address a row in each.
 *
 * Kept as one literal map rather than spread across five models so the recycle
 * bin can loop over every type, and so a typo in a table name is impossible.
 */
function soft_delete_types(): array
{
    return [
        'client' => [
            'table'    => 'clients',
            'alias'    => 'c',
            'label'    => 'company_name',
            'title'    => 'Clients',
            'summary'  => 'Name',
            'icon'     => 'bi-building',
            'children' => ['deals', 'tasks', 'activities'],
        ],
        'lead' => [
            'table'    => 'leads',
            'alias'    => 'l',
            'label'    => 'lead_name',
            'title'    => 'Leads',
            'summary'  => 'Name',
            'icon'     => 'bi-person-lines-fill',
            'children' => ['deals', 'tasks', 'activities'],
        ],
        'deal' => [
            'table'    => 'deals',
            'alias'    => 'd',
            'label'    => 'deal_title',
            'title'    => 'Deals',
            'summary'  => 'Deal',
            'icon'     => 'bi-briefcase',
            'children' => [],
        ],
        'task' => [
            'table'    => 'tasks',
            'alias'    => 't',
            'label'    => 'title',
            'title'    => 'Tasks',
            'summary'  => 'Task',
            'icon'     => 'bi-check2-square',
            'children' => [],
        ],
        'activity' => [
            'table'    => 'activities',
            'alias'    => 'a',
            'label'    => 'title',
            'title'    => 'Activities',
            'summary'  => 'Activity',
            'icon'     => 'bi-clock-history',
            'children' => [],
        ],
    ];
}

/** The descriptor for one soft-deletable type, or null when unknown. */
function soft_delete_type(string $type): ?array
{
    return soft_delete_types()[$type] ?? null;
}

/**
 * Stamp a row as deleted.
 *
 * @param string $type one of soft_delete_types()
 * @param int    $id
 */
function soft_delete_row(string $type, int $id): bool
{
    $meta = soft_delete_type($type);
    if (!$meta) {
        return false;
    }
    $stmt = db()->prepare(
        "UPDATE {$meta['table']} SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL"
    );
    $stmt->execute([current_user_id(), $id]);
    return $stmt->rowCount() > 0;
}

/** Clear the stamp, bringing a row and all of its children back. */
function soft_delete_restore(string $type, int $id): bool
{
    $meta = soft_delete_type($type);
    if (!$meta) {
        return false;
    }
    $stmt = db()->prepare(
        "UPDATE {$meta['table']} SET deleted_at = NULL, deleted_by = NULL WHERE id = ? AND deleted_at IS NOT NULL"
    );
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/**
 * Permanently remove a row. This is the only hard delete left, and it is
 * reachable only from the recycle bin, where the UI asks the admin to confirm
 * and states exactly how many child rows go with it.
 */
function soft_delete_purge(string $type, int $id): bool
{
    $meta = soft_delete_type($type);
    if (!$meta) {
        return false;
    }
    $stmt = db()->prepare("DELETE FROM {$meta['table']} WHERE id = ? AND deleted_at IS NOT NULL");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/** Fetch one deleted row for the recycle bin, or null. */
function soft_delete_find(string $type, int $id): ?array
{
    $meta = soft_delete_type($type);
    if (!$meta) {
        return null;
    }
    $a = $meta['alias'];
    $stmt = db()->prepare(
        "SELECT {$a}.*, u.name AS deleted_by_name
         FROM {$meta['table']} {$a}
         LEFT JOIN users u ON u.id = {$a}.deleted_by
         WHERE {$a}.id = ? AND {$a}.deleted_at IS NOT NULL"
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Paginated list of deleted rows for one type.
 *
 * only_deleted flips list_query's predicate to IS NOT NULL, so the bin shows
 * exactly what has been deleted and nothing that is still live.
 */
function soft_delete_list(string $type, array $options = []): array
{
    $meta = soft_delete_type($type);
    if (!$meta) {
        return ['rows' => [], 'total' => 0, 'offset' => 0];
    }
    $a = $meta['alias'];

    // Newest deletion first unless the request asks otherwise: the record an
    // admin has just deleted is the one they are looking for.
    $options['dir'] = $options['dir'] ?? 'desc';

    return list_query([
        'select'       => "{$a}.*, u.name AS deleted_by_name",
        'from'         => "FROM {$meta['table']} {$a}
                           LEFT JOIN users u ON u.id = {$a}.deleted_by",
        'search'       => [$a . '.' . $meta['label'], 'u.name'],
        'options'      => $options,
        'sort'         => [
            'deleted' => $a . '.deleted_at',
            'name'    => $a . '.' . $meta['label'],
        ],
        'sort_default' => 'deleted',
        // Newest deletion first: that is the one an admin is looking for.
        'order_by'     => $a . '.id ASC',
        'only_deleted' => true,
        'soft_delete'  => [$a],
    ]);
}

/**
 * Total rows currently in the bin, for the admin nav badge.
 *
 * One UNION ALL rather than five separate COUNTs, because this runs on every
 * admin page load for the sidebar badge.
 */
function soft_delete_count(): int
{
    $parts = [];
    foreach (soft_delete_types() as $meta) {
        $parts[] = 'SELECT COUNT(*) AS total FROM ' . $meta['table'] . ' WHERE deleted_at IS NOT NULL';
    }
    return (int) db()->query(implode(' UNION ALL ', $parts))->fetchColumn();
}

/** Count per type, for the recycle bin tabs. */
function soft_delete_counts(): array
{
    $counts = [];
    foreach (soft_delete_types() as $type => $meta) {
        $counts[$type] = (int) db()->query("SELECT COUNT(*) FROM {$meta['table']} WHERE deleted_at IS NOT NULL")->fetchColumn();
    }
    return $counts;
}

/**
 * How many child rows a purge would destroy, so the confirmation can be honest
 * about the blast radius rather than generic.
 */
function soft_delete_child_count(string $type, int $id): int
{
    $meta = soft_delete_type($type);
    if (!$meta || !$meta['children']) {
        return 0;
    }
    $fks = ['deals' => 'client_id', 'tasks' => 'client_id', 'activities' => 'client_id'];
    if ($type === 'lead') {
        $fks = ['deals' => 'lead_id', 'tasks' => 'lead_id', 'activities' => 'lead_id'];
    }

    $total = 0;
    foreach ($meta['children'] as $child) {
        $total += (int) db()->query(
            "SELECT COUNT(*) FROM {$child} WHERE {$fks[$child]} = $id"
        )->fetchColumn();
    }
    return $total;
}

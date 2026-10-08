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
 * tenant_id is part of the WHERE on every write here. These functions address
 * rows by bare id from a URL, so without it an admin in one workspace could
 * delete, restore or permanently purge another workspace's records simply by
 * editing the id in the query string.
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
    // Read first so the audit row can name the record rather than just its id.
    // soft_delete_find() only matches rows already in the bin, which is exactly
    // the wrong set here: the row being deleted is still live.
    $before = soft_delete_read_any($type, $id);

    $stmt = db()->prepare(
        "UPDATE {$meta['table']} SET deleted_at = NOW(), deleted_by = ?
         WHERE tenant_id = ? AND id = ? AND deleted_at IS NULL"
    );
    $stmt->execute([current_user_id(), tenant_id(), $id]);
    $done = $stmt->rowCount() > 0;

    if ($done && $before !== null) {
        audit_record_simple('delete', $type, $id, (string) ($before[$meta['label']] ?? ('#' . $id)));
    }

    return $done;
}

/**
 * Read one row whether or not it is currently in the recycle bin.
 *
 * soft_delete_find() is scoped to deleted rows because every caller of it wants
 * something from the bin. This is the counterpart for the moment just before a
 * row enters it.
 */
function soft_delete_read_any(string $type, int $id): ?array
{
    $meta = soft_delete_type($type);
    if (!$meta) {
        return null;
    }
    $a = $meta['alias'];
    $stmt = db()->prepare(
        "SELECT {$a}.* FROM {$meta['table']} {$a}
         WHERE {$a}.tenant_id = ? AND {$a}.id = ?"
    );
    $stmt->execute([tenant_id(), $id]);
    return $stmt->fetch() ?: null;
}

/** Clear the stamp, bringing a row and all of its children back. */
function soft_delete_restore(string $type, int $id): bool
{
    $meta = soft_delete_type($type);
    if (!$meta) {
        return false;
    }
    $before = soft_delete_find($type, $id);

    $stmt = db()->prepare(
        "UPDATE {$meta['table']} SET deleted_at = NULL, deleted_by = NULL
         WHERE tenant_id = ? AND id = ? AND deleted_at IS NOT NULL"
    );
    $stmt->execute([tenant_id(), $id]);
    $done = $stmt->rowCount() > 0;

    if ($done && $before !== null) {
        audit_record_simple('restore', $type, $id, (string) ($before[$meta['label']] ?? ('#' . $id)));
    }

    return $done;
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
    // Read before the delete. Afterwards there is nothing left to name, and an
    // audit row that can only say "client #42" is much less use.
    $before = soft_delete_find($type, $id);

    $stmt = db()->prepare(
        "DELETE FROM {$meta['table']} WHERE tenant_id = ? AND id = ? AND deleted_at IS NOT NULL"
    );
    $stmt->execute([tenant_id(), $id]);
    $done = $stmt->rowCount() > 0;

    // The one action whose audit row has to outlive its subject: after this,
    // the record it describes is gone for good. entity_id carries no foreign key
    // for exactly this reason.
    if ($done) {
        $label = $before !== null
            ? (string) ($before[$meta['label']] ?? ('#' . $id))
            : ('#' . $id);
        audit_record_simple('purge', $type, $id, $label);
    }

    return $done;
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
         LEFT JOIN users u ON u.id = {$a}.deleted_by AND u.tenant_id = {$a}.tenant_id
         WHERE {$a}.tenant_id = ? AND {$a}.id = ? AND {$a}.deleted_at IS NOT NULL"
    );
    $stmt->execute([tenant_id(), $id]);
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
                           LEFT JOIN users u ON u.id = {$a}.deleted_by AND u.tenant_id = {$a}.tenant_id",
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
        // The alias is dynamic (one per soft-deletable type), so the tenant
        // predicate cannot use the 'tenant' shorthand's default of ['t'].
        'tenant'       => [$a],
    ]);
}

/**
 * Total rows currently in the bin, for the admin nav badge.
 *
 * One UNION ALL rather than five separate COUNTs, because this runs on every
 * admin page load for the sidebar badge. The tenant id is repeated once per
 * branch because the whole statement is built as one string and bound
 * positionally.
 */
function soft_delete_count(): int
{
    $parts = [];
    $params = [];
    foreach (soft_delete_types() as $meta) {
        $parts[] = 'SELECT COUNT(*) AS total FROM ' . $meta['table']
            . ' WHERE tenant_id = ? AND deleted_at IS NOT NULL';
        $params[] = tenant_id();
    }
    $stmt = db()->prepare(implode(' UNION ALL ', $parts));
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** Count per type, for the recycle bin tabs. */
function soft_delete_counts(): array
{
    $counts = [];
    foreach (soft_delete_types() as $type => $meta) {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM {$meta['table']} WHERE tenant_id = ? AND deleted_at IS NOT NULL"
        );
        $stmt->execute([tenant_id()]);
        $counts[$type] = (int) $stmt->fetchColumn();
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
        // Table and column names come from the literal maps above and $id is
        // cast to int, so there is no injection path here.
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM {$child} WHERE tenant_id = ? AND {$fks[$child]} = ?"
        );
        $stmt->execute([tenant_id(), (int) $id]);
        $total += (int) $stmt->fetchColumn();
    }
    return $total;
}

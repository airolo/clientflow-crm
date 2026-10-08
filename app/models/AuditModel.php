<?php
/**
 * Audit log - who changed what, field by field.
 *
 * Append-only. Nothing in the application updates or deletes an audit row, and
 * there is no UI for doing so. That is the property that makes it worth keeping;
 * a log that can be edited is a log that proves nothing.
 *
 * It is a convention, not a guarantee. Anyone with DELETE on the table, or with
 * filesystem access to a dump, can still erase it. Real tamper-evidence means
 * shipping rows somewhere the app cannot write to, which this build does not do.
 *
 * Two details are deliberate:
 *
 *  - `user_name` and `entity_label` are **copied**, not joined. An audit trail
 *    you cannot read because the person has left the company is not a trail.
 *  - `entity_id` has **no foreign key**. A row has to outlive the record it
 *    describes, including after "Delete forever".
 */

declare(strict_types=1);

/** The actions a row can record. Used to validate before writing. */
function audit_actions(): array
{
    return [
        'create', 'update', 'delete', 'restore', 'purge',
        'login', 'login_failed', 'logout', 'signup', 'import',
        'suspend', 'activate',
    ];
}

/** True when $action is one this log records. */
function audit_action_is_valid(string $action): bool
{
    return in_array($action, audit_actions(), true);
}

/**
 * Bootstrap contextual colour for an action badge.
 *
 * Grouped by meaning rather than listed individually: destructive actions read
 * red, sign-in history neutral, routine edits blue. A log where every row is a
 * different colour is harder to scan, not easier.
 */
function audit_action_badge(string $action): string
{
    switch ($action) {
        case 'delete':
        case 'purge':
            return 'danger';
        case 'suspend':
            return 'danger';
        case 'restore':
        case 'activate':
            return 'success';
        case 'login':
            return 'secondary';
        case 'login_failed':
            return 'warning';
        case 'create':
        case 'update':
            return 'primary';
        default:
            return 'info';
    }
}

/**
 * Compare two versions of a record and return only what actually changed.
 *
 * This is the whole reason the log is worth having: recording "record 42 was
 * updated" tells you nothing, while "status: inactive -> active" tells you what
 * happened and is what an auditor actually asks for.
 *
 * $fields is an explicit list of the columns worth comparing. Two reasons it is
 * not just "compare every key":
 *  - Several columns are noise. `updated_at` changes on every save and would
 *    put a meaningless entry in the log every time.
 *  - Comparing ids would record "assigned_to: 3 -> 4" instead of the two people's
 *    names, which is unreadable. Pass resolved names and compare those.
 *
 * @param array $before the record as it was
 * @param array $after  the record as it will be
 * @param array $fields column => human label
 * @return array [['field'=>..,'label'=>..,'from'=>..,'to'=>..], ...]
 */
function audit_diff(array $before, array $after, array $fields): array
{
    $changes = [];

    foreach ($fields as $column => $label) {
        $old = $before[$column] ?? null;
        $new = $after[$column] ?? null;

        // Treat null and '' as the same absence. Without this, clearing a field
        // that was already empty would log a change on every save.
        $oldEmpty = $old === null || $old === '';
        $newEmpty = $new === null || $new === '';
        if ($oldEmpty && $newEmpty) {
            continue;
        }

        if ((string) $old === (string) $new) {
            continue;
        }

        $changes[] = [
            'field' => $column,
            'label' => $label,
            'from'  => $oldEmpty ? null : $old,
            'to'    => $newEmpty ? null : $new,
        ];
    }

    return $changes;
}

/**
 * Append one row to the log.
 *
 * Never throws. An audit failure must not roll back the business operation that
 * already succeeded - losing an edit because the log was full would be a far
 * worse outcome than a missing audit row. The failure goes to the error log
 * instead, which is the one place someone will actually look.
 *
 * @param string     $action     one of audit_actions()
 * @param string     $entityType client, lead, deal, task, activity, user, tenant
 * @param int|null   $entityId
 * @param string|null $label
 * @param array      $changes    output of audit_diff(), or [] for non-update actions
 * @param int|null   $userId     null for system actions, or before sign-in
 * @param int|null   $tenantId   for events recorded before there is a session,
 *                                such as a failed sign-in. Defaults to the
 *                                session's workspace.
 * @param string|null $userName  for events recorded before there is a session,
 *                                where the name is not in $_SESSION yet
 */
function audit_record(
    string $action,
    string $entityType,
    ?int $entityId = null,
    ?string $label = null,
    array $changes = [],
    ?int $userId = null,
    ?int $tenantId = null,
    ?string $userName = null
): void {
    try {
        if (!audit_action_is_valid($action)) {
            error_log('ClientFlow audit: refusing unknown action "' . $action . '"');
            return;
        }

        // Every audited operation happens inside one request against one
        // workspace, so the session's tenant is the right one by default.
        if ($tenantId === null) {
            try {
                $tenantId = tenant_id();
            } catch (Throwable $e) {
                // No session tenant yet. Only a failed sign-in against a
                // workspace slug that does not exist lands here, and
                // audit_log.tenant_id is NOT NULL because a row that belongs to
                // no workspace could never be listed or scoped.
                //
                // Skipping is deliberate rather than filing it somewhere else:
                // login_attempts already keeps those failures, and inventing a
                // tenant for the row would be a lie about where it came from.
                return;
            }
        }

        // The session's user, falling back to the name passed in for the handful
        // of calls made before or without a session (login, login_failed).
        $user = current_user();
        $name = $user['name'] ?? null;
        if ($userName !== null) {
            $name = $userName;
        }
        if ($name === null && $userId === null) {
            $name = 'System';
        }

        $payload = null;
        if ($changes !== []) {
            $payload = json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                error_log('ClientFlow audit: could not encode the change list');
                return;
            }
        }

        $stmt = db()->prepare(
            'INSERT INTO audit_log
                (tenant_id, user_id, user_name, action, entity_type, entity_id, entity_label, changes, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tenantId,
            $userId ?? current_user_id(),
            $name,
            $action,
            $entityType,
            $entityId,
            $label === null ? null : mb_substr($label, 0, 200),
            $payload,
            substr(client_ip(), 0, 45),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        // Swallowed on purpose, and logged. See the note above.
        error_log('ClientFlow audit write failed: ' . $e->getMessage());
    }
}

/**
 * Append a row describing an update, but only if something really changed.
 *
 * The models call this on every save, including the ones where the user opened
 * a form and pressed save without touching anything. Logging those would bury
 * the real edits in noise, which is how audit logs get ignored.
 *
 * @return bool whether a row was written
 */
function audit_record_update(string $entityType, int $entityId, array $before, array $after, array $fields, ?string $label = null): bool
{
    $changes = audit_diff($before, $after, $fields);
    if ($changes === []) {
        return false;
    }

    audit_record('update', $entityType, $entityId, $label ?? audit_entity_label($after), $changes);
    return true;
}

/** Append a row describing a create, with the field values that were set. */
function audit_record_create(string $entityType, int $entityId, array $values, array $fields, ?string $label = null): void
{
    // Stored as from=null, to=value so the log reads the same way as an update:
    // a list of what this record was created with.
    $changes = [];
    foreach ($fields as $column => $fieldLabel) {
        $value = $values[$column] ?? null;
        if ($value === null || $value === '') {
            continue;
        }
        $changes[] = [
            'field' => $column,
            'label' => $fieldLabel,
            'from'  => null,
            'to'    => $value,
        ];
    }

    audit_record('create', $entityType, $entityId, $label ?? audit_entity_label($values), $changes);
}

/** Append a row describing a delete, a restore or a purge. */
function audit_record_simple(string $action, string $entityType, int $entityId, ?string $label = null): void
{
    audit_record($action, $entityType, $entityId, $label);
}

/**
 * A record's label, for the denormalised entity_label column.
 *
 * Looks up whichever column this type is titled by, and falls back to the id so
 * the row is still identifiable when the title column is somehow empty.
 */
function audit_entity_label(array $row): ?string
{
    foreach (['company_name', 'lead_name', 'deal_title', 'title', 'name', 'email'] as $column) {
        if (!empty($row[$column])) {
            return (string) $row[$column];
        }
    }
    if (!empty($row['id'])) {
        return '#' . (int) $row['id'];
    }
    return null;
}

/** The fields worth comparing for each record type, as column => label. */
function audit_fields(string $entityType): array
{
    switch ($entityType) {
        case 'client':
            return [
                'company_name'   => 'Company',
                'contact_person' => 'Contact',
                'email'          => 'Email',
                'phone'          => 'Phone',
                'address'        => 'Address',
                'status'         => 'Status',
                'notes'          => 'Notes',
            ];
        case 'lead':
            return [
                'lead_name'       => 'Name',
                'company'         => 'Company',
                'email'           => 'Email',
                'phone'           => 'Phone',
                'lead_source'     => 'Source',
                'status'          => 'Status',
                'estimated_value' => 'Estimated value',
                'notes'           => 'Notes',
            ];
        case 'deal':
            return [
                'deal_title'          => 'Deal',
                'value'               => 'Value',
                'stage'               => 'Stage',
                'expected_close_date' => 'Expected close',
                'notes'               => 'Notes',
            ];
        case 'task':
            return [
                'title'       => 'Title',
                'description' => 'Description',
                'priority'    => 'Priority',
                'status'      => 'Status',
                'due_date'    => 'Due',
            ];
        case 'activity':
            return [
                'type'    => 'Type',
                'title'   => 'Title',
                'details' => 'Details',
            ];
        case 'user':
            return [
                'name'      => 'Name',
                'email'     => 'Email',
                'role'      => 'Role',
                'phone'     => 'Phone',
                'is_active' => 'Active',
            ];
        default:
            return [];
    }
}

/**
 * The audit log, paginated and filtered.
 *
 * Filterable by action, record type, person and date range, because the three
 * questions anyone actually asks are "what did they delete", "what happened to
 * this record" and "what did this person do".
 *
 * @return array ['rows' => [], 'total' => int, 'offset' => int]
 */
function audit_list(array $options = []): array
{
    $action = (string) ($options['action'] ?? '');
    $type   = (string) ($options['entity_type'] ?? '');
    $userId = (int) ($options['user_id'] ?? 0);
    $from   = trim((string) ($options['date_from'] ?? ''));
    $to     = trim((string) ($options['date_to'] ?? ''));
    $search = trim((string) ($options['search'] ?? ''));
    $page   = max(1, (int) ($options['page'] ?? 1));
    $perPage = max(1, min(100, (int) ($options['per_page'] ?? 25)));

    // The tenant predicate is written out literally in each statement below
    // rather than assembled into a fragment first. That is deliberate: it keeps
    // the scoping visible to anyone reading the query, and it is what lets
    // tools\check_tenancy.ps1 verify it without having to evaluate PHP.
    $extra  = [];
    $params = [tenant_id()];

    if ($action !== '' && audit_action_is_valid($action)) {
        $extra[] = 'a.action = ?';
        $params[] = $action;
    }
    if ($type !== '' && preg_match('/^[a-z]{2,20}$/', $type)) {
        $extra[] = 'a.entity_type = ?';
        $params[] = $type;
    }
    if ($userId > 0) {
        $extra[] = 'a.user_id = ?';
        $params[] = $userId;
    }
    if ($from !== '') {
        $extra[] = 'a.created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        $extra[] = 'a.created_at <= ?';
        $params[] = $to . ' 23:59:59';
    }
    if ($search !== '') {
        // Bound, so the LIKE wildcards are data rather than syntax.
        $extra[] = '(a.entity_label LIKE ? OR a.user_name LIKE ? OR a.entity_type LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

// Only the optional filters are assembled. The tenant predicate stays in the
    // literal SQL so that scoping is visible in the query text itself.
$filterSql = $extra === [] ? '' : (' AND ' . implode(' AND ', $extra));

$countStmt = db()->prepare(
    'SELECT COUNT(*) FROM audit_log a WHERE a.tenant_id = ?' . $filterSql
);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$offset = ($page - 1) * $perPage;
$stmt = db()->prepare(
    'SELECT a.* FROM audit_log a WHERE a.tenant_id = ?' . $filterSql . '
          ORDER BY a.created_at DESC, a.id DESC
          LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Decoded here rather than in the view, so every consumer gets the same
    // shape. A malformed row is shown as having no detail rather than taking
    // the whole log page down.
    foreach ($rows as $i => $row) {
        $rows[$i]['changes'] = $row['changes'] === null
            ? []
            : (json_decode((string) $row['changes'], true) ?: []);
    }

    return ['rows' => $rows, 'total' => $total, 'offset' => $offset];
}

/** One record's history, newest first. */
function audit_for_entity(string $entityType, int $entityId, int $limit = 25): array
{
    $stmt = db()->prepare(
        'SELECT * FROM audit_log
         WHERE tenant_id = ? AND entity_type = ? AND entity_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT ' . max(1, min(100, $limit))
    );
    $stmt->execute([tenant_id(), $entityType, $entityId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $i => $row) {
        $rows[$i]['changes'] = $row['changes'] === null
            ? []
            : (json_decode((string) $row['changes'], true) ?: []);
    }
    return $rows;
}

/** How many rows the log holds for this workspace, for the nav badge. */
function audit_count(): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id = ?');
    $stmt->execute([tenant_id()]);
    return (int) $stmt->fetchColumn();
}

/** Distinct record types present in this workspace's log, for the filter. */
function audit_entity_types(): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT entity_type FROM audit_log WHERE tenant_id = ? ORDER BY entity_type'
    );
    $stmt->execute([tenant_id()]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
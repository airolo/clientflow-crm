<?php
/**
 * Task model - follow-ups and to-dos.
 */

declare(strict_types=1);

function task_sort_columns(): array
{
    return [
        'title'    => 't.title',
        'due'      => 't.due_date',
        'priority' => 'FIELD(t.priority, "high","medium","low")',
        'status'   => 't.status',
        'owner'    => 'u.name',
        'created'  => 't.created_at',
    ];
}

/** Paginated, searchable, filterable task list. */
function task_list(array $options = []): array
{
    // Overdue is a composite condition, so it is written out rather than
    // built from enum_filter()/id_filter().
    $overdue = !empty($options['overdue']);

    return list_query([
        'select'  => 't.*, c.company_name, l.lead_name, u.name AS owner_name',
        'from'    => 'FROM tasks t
                      LEFT JOIN clients c ON c.id = t.client_id AND c.tenant_id = t.tenant_id
                      LEFT JOIN leads   l ON l.id = t.lead_id AND l.tenant_id = t.tenant_id
                      LEFT JOIN users   u ON u.id = t.assigned_to AND u.tenant_id = t.tenant_id',
        'search'  => ['t.title', 't.description', 'c.company_name', 'l.lead_name'],
        'options' => $options,
        'filters' => [
            enum_filter('status', 't.status', task_statuses(), $options),
            enum_filter('priority', 't.priority', task_priorities(), $options),
            id_filter('assigned_to', 't.assigned_to', $options),
            id_filter('client_id', 't.client_id', $options),
            id_filter('lead_id', 't.lead_id', $options),
            $overdue
                ? ['overdue', 't.due_date IS NOT NULL AND t.due_date < CURDATE() AND t.status <> "completed"', []]
                : null,
        ],
        'sort'        => task_sort_columns(),
        'sort_default' => 'due',
        'order_by'    => 't.id DESC',
        'soft_delete' => ['t'],
        'tenant'      => ['t'],
        // A task disappears with the client or lead it was logged against.
        'where_extra' => [
            '(t.client_id IS NULL OR c.deleted_at IS NULL)',
            '(t.lead_id   IS NULL OR l.deleted_at IS NULL)',
        ],
    ]);
}

function task_find(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT t.*, c.company_name, l.lead_name, u.name AS owner_name, cu.name AS creator_name
         FROM tasks t
         LEFT JOIN clients c  ON c.id = t.client_id AND c.tenant_id = t.tenant_id
         LEFT JOIN leads   l  ON l.id = t.lead_id AND l.tenant_id = t.tenant_id
         LEFT JOIN users   u  ON u.id = t.assigned_to AND u.tenant_id = t.tenant_id
         LEFT JOIN users   cu ON cu.id = t.created_by AND cu.tenant_id = t.tenant_id
         WHERE t.tenant_id = ? AND t.id = ? AND t.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([tenant_id(), $id]);
    return $stmt->fetch() ?: null;
}

function task_create(array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO tasks (tenant_id, title, description, client_id, lead_id, due_date, priority, status, assigned_to, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        tenant_id(),
        $data['title'],
        null_if_empty($data['description'] ?? null),
        $data['client_id'] ?: null,
        $data['lead_id'] ?: null,
        null_if_empty($data['due_date'] ?? null),
        $data['priority'],
        $data['status'] ?? 'pending',
        $data['assigned_to'] ?: null,
        $data['created_by'],
    ]);
    $id = (int) db()->lastInsertId();

    audit_record_create('task', $id, $data + ['id' => $id], audit_fields('task'), (string) $data['title']);

    return $id;
}

function task_update(int $id, array $data): void
{
    $before = task_find($id);

    $stmt = db()->prepare(
        'UPDATE tasks
         SET title = ?, description = ?, client_id = ?, lead_id = ?, due_date = ?,
             priority = ?, status = ?, assigned_to = ?
         WHERE tenant_id = ? AND id = ?'
    );
    $stmt->execute([
        $data['title'],
        null_if_empty($data['description'] ?? null),
        $data['client_id'] ?: null,
        $data['lead_id'] ?: null,
        null_if_empty($data['due_date'] ?? null),
        $data['priority'],
        $data['status'],
        $data['assigned_to'] ?: null,
        tenant_id(),
        $id,
    ]);

    if ($before !== null) {
        $fields = audit_fields('task') + ['assigned_to' => 'Assigned to'];
        $after = $before;
        foreach (array_keys($fields) as $column) {
            $after[$column] = $data[$column] ?? $before[$column];
        }
        $before['assigned_to'] = audit_user_name($before['assigned_to'] ?? null);
        $after['assigned_to']  = audit_user_name($data['assigned_to'] ?? null);

        audit_record_update('task', $id, $before, $after, $fields, (string) ($data['title'] ?? ''));
    }
}

/** Mark a task completed / reopen it, stamping completed_at. */
function task_set_status(int $id, string $status): void
{
    $before = task_find($id);

    $completedAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;
    $stmt = db()->prepare('UPDATE tasks SET status = ?, completed_at = ? WHERE tenant_id = ? AND id = ? AND deleted_at IS NULL');
    $stmt->execute([$status, $completedAt, tenant_id(), $id]);

    // completed_at is deliberately not compared. It is derived from status, so
    // logging it separately would show every completion as two changes.
    if ($before !== null && $before['status'] !== $status) {
        // array_merge, not `$before + [...]`. The + operator keeps the key from
        // the left operand, so `$before + ['status' => $status]` would silently
        // discard the new status and the diff would come back empty.
        $after = array_merge($before, ['status' => $status]);
        audit_record_update('task', $id, $before, $after, ['status' => 'Status'], (string) $before['title']);
    }
}

function task_delete(int $id): void
{
    soft_delete_row('task', $id);
}

function task_restore(int $id): void
{
    soft_delete_restore('task', $id);
}

// Open tasks first, then by due date with undated ones last.
const TASK_RELATED_ORDER = 'r.status <> "completed", r.due_date IS NULL, r.due_date ASC';

/** Tasks attached to a client (client detail page). */
function client_tasks(int $clientId, int $limit = 10): array
{
    return related_list('tasks', 'assigned_to', 'client_id', TASK_RELATED_ORDER, $clientId, $limit);
}

/** Tasks attached to a lead (lead detail page). */
function lead_tasks(int $leadId, int $limit = 10): array
{
    return related_list('tasks', 'assigned_to', 'lead_id', TASK_RELATED_ORDER, $leadId, $limit);
}

/** Dashboard figure: tasks still open for the signed-in user. */
function task_count_pending(?int $assignedTo = null): int
{
    if ($assignedTo) {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM tasks WHERE tenant_id = ? AND deleted_at IS NULL
             AND status <> "completed" AND (assigned_to = ? OR assigned_to IS NULL)'
        );
        $stmt->execute([tenant_id(), $assignedTo]);
        return (int) $stmt->fetchColumn();
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM tasks WHERE tenant_id = ? AND deleted_at IS NULL AND status <> "completed"');
    $stmt->execute([tenant_id()]);
    return (int) $stmt->fetchColumn();
}

/** Sidebar badge count. */
function task_count_open_for_sidebar(?int $userId): int
{
    if (!$userId) {
        return 0;
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM tasks WHERE tenant_id = ? AND deleted_at IS NULL
                        AND status <> "completed" AND assigned_to = ?');
    $stmt->execute([tenant_id(), $userId]);
    return (int) $stmt->fetchColumn();
}

/** Tasks due today or overdue. */
function task_due_soon(int $limit = 6): array
{
    $stmt = db()->prepare(
        'SELECT t.*, c.company_name, l.lead_name, u.name AS owner_name
         FROM tasks t
         LEFT JOIN clients c ON c.id = t.client_id AND c.tenant_id = t.tenant_id
         LEFT JOIN leads   l ON l.id = t.lead_id AND l.tenant_id = t.tenant_id
         LEFT JOIN users   u ON u.id = t.assigned_to AND u.tenant_id = t.tenant_id
         WHERE t.tenant_id = ? AND t.deleted_at IS NULL
           AND (t.client_id IS NULL OR c.deleted_at IS NULL)
           AND (t.lead_id   IS NULL OR l.deleted_at IS NULL)
           AND t.status <> "completed" AND t.due_date IS NOT NULL AND t.due_date <= CURDATE()
         ORDER BY t.due_date ASC, FIELD(t.priority, "high","medium","low")
         LIMIT ?'
    );
    $stmt->bindValue(1, tenant_id(), PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function task_overdue_count(): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM tasks
         WHERE tenant_id = ? AND deleted_at IS NULL
           AND status <> "completed" AND due_date IS NOT NULL AND due_date < CURDATE()'
    );
    $stmt->execute([tenant_id()]);
    return (int) $stmt->fetchColumn();
}

/** Live task count per status, for the tab badges on the task board. */
function task_count_by_status(): array
{
    $stmt = db()->prepare(
        'SELECT status, COUNT(*) AS total FROM tasks WHERE tenant_id = ? AND deleted_at IS NULL GROUP BY status'
    );
    $stmt->execute([tenant_id()]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Every live task, unpaginated, for CSV export.
 *
 * @return Generator
 */
function task_export_rows(): Generator
{
    $stmt = db()->prepare(
        "SELECT t.title, t.description, c.company_name AS client_name,
                l.lead_name, t.status, t.priority, t.due_date, t.completed_at,
                u.name AS owner_name, t.created_at
         FROM tasks t
         LEFT JOIN clients c ON c.id = t.client_id AND c.tenant_id = t.tenant_id
         LEFT JOIN leads   l ON l.id = t.lead_id AND l.tenant_id = t.tenant_id
         LEFT JOIN users   u ON u.id = t.assigned_to AND u.tenant_id = t.tenant_id
         WHERE t.tenant_id = ? AND t.deleted_at IS NULL
         ORDER BY t.due_date IS NULL, t.due_date ASC, t.id ASC"
    );
    $stmt->execute([tenant_id()]);
    while ($row = $stmt->fetch()) {
        yield $row;
    }
}

/**
 * Validate a task form. Returns an array of field => error message.
 */
function task_validate(array $data): array
{
    $errors = [];

    if (($data['title'] ?? '') === '') {
        $errors['title'] = 'Task title is required.';
    }

    if (!is_valid_option($data['priority'] ?? '', task_priorities())) {
        $errors['priority'] = 'Choose a valid priority.';
    }
    if (!is_valid_option($data['status'] ?? '', task_statuses())) {
        $errors['status'] = 'Choose a valid status.';
    }

    $due = trim((string) ($data['due_date'] ?? ''));
    if ($due !== '') {
        $parsed = DateTime::createFromFormat('Y-m-d', $due);
        if (!$parsed || $parsed->format('Y-m-d') !== $due) {
            $errors['due_date'] = 'Enter a valid due date.';
        }
    }

    return $errors + length_errors([
        'title'       => [$data['title'] ?? '', 180, 'Task title'],
        'description' => [$data['description'] ?? '', 3000, 'Description'],
    ]);
}
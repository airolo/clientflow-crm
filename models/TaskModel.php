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
    $search     = trim((string) ($options['search'] ?? ''));
    $status     = (string) ($options['status'] ?? '');
    $priority   = (string) ($options['priority'] ?? '');
    $assignedTo = (int) ($options['assigned_to'] ?? 0);
    $clientId   = (int) ($options['client_id'] ?? 0);
    $leadId     = (int) ($options['lead_id'] ?? 0);
    $overdue    = !empty($options['overdue']);
    $page       = max(1, (int) ($options['page'] ?? 1));
    $perPage    = (int) ($options['per_page'] ?? ROWS_PER_PAGE);
    $offset     = ($page - 1) * $perPage;

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(t.title LIKE ? OR t.description LIKE ? OR c.company_name LIKE ? OR l.lead_name LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($status !== '' && is_valid_option($status, task_statuses())) {
        $where[] = 't.status = ?';
        $params[] = $status;
    }
    if ($priority !== '' && is_valid_option($priority, task_priorities())) {
        $where[] = 't.priority = ?';
        $params[] = $priority;
    }
    if ($assignedTo > 0) {
        $where[] = 't.assigned_to = ?';
        $params[] = $assignedTo;
    }
    if ($clientId > 0) {
        $where[] = 't.client_id = ?';
        $params[] = $clientId;
    }
    if ($leadId > 0) {
        $where[] = 't.lead_id = ?';
        $params[] = $leadId;
    }
    if ($overdue) {
        $where[] = 't.due_date IS NOT NULL AND t.due_date < CURDATE() AND t.status <> "completed"';
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $joins = 'FROM tasks t
              LEFT JOIN clients c ON c.id = t.client_id
              LEFT JOIN leads   l ON l.id = t.lead_id
              LEFT JOIN users   u ON u.id = t.assigned_to';

    $countStmt = db()->prepare('SELECT COUNT(*) ' . $joins . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sortSql = order_by(task_sort_columns(), 'due');

    $sql = 'SELECT t.*, c.company_name, l.lead_name, u.name AS owner_name ' . $joins
        . $whereSql . " ORDER BY $sortSql, t.id DESC LIMIT $perPage OFFSET $offset";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(), 'total' => $total, 'offset' => $offset];
}

function task_find(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT t.*, c.company_name, l.lead_name, u.name AS owner_name, cu.name AS creator_name
         FROM tasks t
         LEFT JOIN clients c  ON c.id = t.client_id
         LEFT JOIN leads   l  ON l.id = t.lead_id
         LEFT JOIN users   u  ON u.id = t.assigned_to
         LEFT JOIN users   cu ON cu.id = t.created_by
         WHERE t.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function task_create(array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO tasks (title, description, client_id, lead_id, due_date, priority, status, assigned_to, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
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
    return (int) db()->lastInsertId();
}

function task_update(int $id, array $data): void
{
    $stmt = db()->prepare(
        'UPDATE tasks
         SET title = ?, description = ?, client_id = ?, lead_id = ?, due_date = ?,
             priority = ?, status = ?, assigned_to = ?
         WHERE id = ?'
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
        $id,
    ]);
}

/** Mark a task completed / reopen it, stamping completed_at. */
function task_set_status(int $id, string $status): void
{
    $completedAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;
    $stmt = db()->prepare('UPDATE tasks SET status = ?, completed_at = ? WHERE id = ?');
    $stmt->execute([$status, $completedAt, $id]);
}

function task_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM tasks WHERE id = ?');
    $stmt->execute([$id]);
}

/** Tasks attached to a client (client detail page). */
function client_tasks(int $clientId, int $limit = 10): array
{
    $stmt = db()->prepare(
        'SELECT t.*, u.name AS owner_name
         FROM tasks t
         LEFT JOIN users u ON u.id = t.assigned_to
         WHERE t.client_id = ?
         ORDER BY t.status <> "completed", t.due_date IS NULL, t.due_date ASC
         LIMIT ?'
    );
    $stmt->bindValue(1, $clientId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Tasks attached to a lead (lead detail page). */
function lead_tasks(int $leadId, int $limit = 10): array
{
    $stmt = db()->prepare(
        'SELECT t.*, u.name AS owner_name
         FROM tasks t
         LEFT JOIN users u ON u.id = t.assigned_to
         WHERE t.lead_id = ?
         ORDER BY t.status <> "completed", t.due_date IS NULL, t.due_date ASC
         LIMIT ?'
    );
    $stmt->bindValue(1, $leadId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Dashboard figure: tasks still open for the signed-in user. */
function task_count_pending(?int $assignedTo = null): int
{
    if ($assignedTo) {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM tasks WHERE status <> "completed" AND (assigned_to = ? OR assigned_to IS NULL)'
        );
        $stmt->execute([$assignedTo]);
        return (int) $stmt->fetchColumn();
    }
    return (int) db()->query('SELECT COUNT(*) FROM tasks WHERE status <> "completed"')->fetchColumn();
}

/** Sidebar badge count. */
function task_count_open_for_sidebar(?int $userId): int
{
    if (!$userId) {
        return 0;
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM tasks WHERE status <> "completed" AND assigned_to = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/** Tasks due today or overdue. */
function task_due_soon(int $limit = 6): array
{
    $stmt = db()->prepare(
        'SELECT t.*, c.company_name, l.lead_name, u.name AS owner_name
         FROM tasks t
         LEFT JOIN clients c ON c.id = t.client_id
         LEFT JOIN leads   l ON l.id = t.lead_id
         LEFT JOIN users   u ON u.id = t.assigned_to
         WHERE t.status <> "completed" AND t.due_date IS NOT NULL AND t.due_date <= CURDATE()
         ORDER BY t.due_date ASC, FIELD(t.priority, "high","medium","low")
         LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function task_overdue_count(): int
{
    return (int) db()->query(
        'SELECT COUNT(*) FROM tasks
         WHERE status <> "completed" AND due_date IS NOT NULL AND due_date < CURDATE()'
    )->fetchColumn();
}

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
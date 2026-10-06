<?php
/**
 * Lead model - early-stage prospects feeding the pipeline.
 */

declare(strict_types=1);

function lead_sort_columns(): array
{
    return [
        'name'    => 'l.lead_name',
        'company' => 'l.company',
        'source'  => 'l.lead_source',
        'status'  => 'l.status',
        'value'   => 'l.estimated_value',
        'owner'   => 'u.name',
        'created' => 'l.created_at',
    ];
}

/** Paginated, searchable, filterable lead list. */
function lead_list(array $options = []): array
{
    $search     = trim((string) ($options['search'] ?? ''));
    $status     = (string) ($options['status'] ?? '');
    $source     = (string) ($options['source'] ?? '');
    $assignedTo = (int) ($options['assigned_to'] ?? 0);
    $page       = max(1, (int) ($options['page'] ?? 1));
    $perPage    = (int) ($options['per_page'] ?? ROWS_PER_PAGE);
    $offset     = ($page - 1) * $perPage;

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(l.lead_name LIKE ? OR l.company LIKE ? OR l.email LIKE ? OR l.phone LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($status !== '' && is_valid_option($status, lead_statuses())) {
        $where[] = 'l.status = ?';
        $params[] = $status;
    }
    if ($source !== '' && is_valid_option($source, lead_sources())) {
        $where[] = 'l.lead_source = ?';
        $params[] = $source;
    }
    if ($assignedTo > 0) {
        $where[] = 'l.assigned_to = ?';
        $params[] = $assignedTo;
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $sortSql  = order_by(lead_sort_columns(), 'created');

    $countStmt = db()->prepare('SELECT COUNT(*) FROM leads l' . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT l.*, u.name AS owner_name
            FROM leads l
            LEFT JOIN users u ON u.id = l.assigned_to"
        . $whereSql
        . " ORDER BY $sortSql, l.id DESC LIMIT $perPage OFFSET $offset";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(), 'total' => $total, 'offset' => $offset];
}

function lead_find(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT l.*, u.name AS owner_name, cu.name AS creator_name,
                (SELECT COUNT(*) FROM deals d WHERE d.lead_id = l.id) AS deal_count
         FROM leads l
         LEFT JOIN users u  ON u.id = l.assigned_to
         LEFT JOIN users cu ON cu.id = l.created_by
         WHERE l.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** All leads as id => label, for task dropdowns. */
function lead_options(): array
{
    $rows = db()->query(
        'SELECT id, lead_name, company FROM leads WHERE status NOT IN ("won","lost") ORDER BY lead_name ASC'
    )->fetchAll();
    $options = [];
    foreach ($rows as $row) {
        $options[$row['id']] = $row['lead_name'] . ($row['company'] ? ' (' . $row['company'] . ')' : '');
    }
    return $options;
}

function lead_create(array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO leads (lead_name, company, email, phone, lead_source, status, estimated_value, assigned_to, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['lead_name'],
        null_if_empty($data['company'] ?? null),
        null_if_empty($data['email'] ?? null),
        null_if_empty($data['phone'] ?? null),
        $data['lead_source'],
        $data['status'],
        (float) ($data['estimated_value'] ?? 0),
        $data['assigned_to'] ?: null,
        null_if_empty($data['notes'] ?? null),
        $data['created_by'],
    ]);
    return (int) db()->lastInsertId();
}

function lead_update(int $id, array $data): void
{
    $stmt = db()->prepare(
        'UPDATE leads
         SET lead_name = ?, company = ?, email = ?, phone = ?, lead_source = ?,
             status = ?, estimated_value = ?, assigned_to = ?, notes = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $data['lead_name'],
        null_if_empty($data['company'] ?? null),
        null_if_empty($data['email'] ?? null),
        null_if_empty($data['phone'] ?? null),
        $data['lead_source'],
        $data['status'],
        (float) ($data['estimated_value'] ?? 0),
        $data['assigned_to'] ?: null,
        null_if_empty($data['notes'] ?? null),
        $id,
    ]);
}

function lead_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM leads WHERE id = ?');
    $stmt->execute([$id]);
}

/** Move a lead to a new status (used by the quick status dropdown). */
function lead_update_status(int $id, string $status): void
{
    $stmt = db()->prepare('UPDATE leads SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
}

/** Dashboard figures. */
function lead_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM leads')->fetchColumn();
}

function lead_count_active(): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM leads WHERE status NOT IN ("won","lost")');
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function lead_count_by_status(): array
{
    return db()->query('SELECT status, COUNT(*) AS total FROM leads GROUP BY status')
        ->fetchAll(PDO::FETCH_KEY_PAIR);
}

function lead_count_by_source(): array
{
    return db()->query('SELECT lead_source, COUNT(*) AS total FROM leads GROUP BY lead_source ORDER BY total DESC')
        ->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Leads that have no deal yet, i.e. not yet on the pipeline. */
function lead_unconverted(int $limit = 6): array
{
    $stmt = db()->prepare(
        'SELECT l.id, l.lead_name, l.company, l.status, l.estimated_value, u.name AS owner_name
         FROM leads l
         LEFT JOIN users u ON u.id = l.assigned_to
         WHERE l.status NOT IN ("won","lost")
           AND NOT EXISTS (SELECT 1 FROM deals d WHERE d.lead_id = l.id)
         ORDER BY l.estimated_value DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function lead_validate(array $data): array
{
    $errors = [];

    if (($data['lead_name'] ?? '') === '') {
        $errors['lead_name'] = 'Lead name is required.';
    }

    $email = $data['email'] ?? '';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if (!is_valid_option($data['lead_source'] ?? '', lead_sources())) {
        $errors['lead_source'] = 'Choose a valid lead source.';
    }
    if (!is_valid_option($data['status'] ?? '', lead_statuses())) {
        $errors['status'] = 'Choose a valid status.';
    }

    $value = (float) ($data['estimated_value'] ?? 0);
    if ($value < 0) {
        $errors['estimated_value'] = 'Estimated value cannot be negative.';
    } elseif ($value > 9999999999.99) {
        $errors['estimated_value'] = 'That value is unrealistically large.';
    }

    // Length limits mirror the VARCHAR widths in database.sql.
    return $errors + length_errors([
        'lead_name'  => [$data['lead_name'] ?? '', 120, 'Lead name'],
        'company'    => [$data['company'] ?? '', 150, 'Company'],
        'email'      => [$data['email'] ?? '', 150, 'Email'],
        'phone'      => [$data['phone'] ?? '', 40, 'Phone'],
        'notes'      => [$data['notes'] ?? '', 2000, 'Notes'],
    ]);
}
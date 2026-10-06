<?php
/**
 * Activity model - the interaction history (calls, emails, meetings, notes).
 */

declare(strict_types=1);

/**
 * Paginated activity feed with optional filters.
 *
 * @param array $options ['search','type','client_id','lead_id','user_id','date_from','date_to','page']
 */
function activity_list(array $options = []): array
{
    $search    = trim((string) ($options['search'] ?? ''));
    $type      = (string) ($options['type'] ?? '');
    $clientId  = (int) ($options['client_id'] ?? 0);
    $leadId    = (int) ($options['lead_id'] ?? 0);
    $userId    = (int) ($options['user_id'] ?? 0);
    $dateFrom  = trim((string) ($options['date_from'] ?? ''));
    $dateTo    = trim((string) ($options['date_to'] ?? ''));
    $page      = max(1, (int) ($options['page'] ?? 1));
    $perPage   = (int) ($options['per_page'] ?? ROWS_PER_PAGE);
    $offset    = ($page - 1) * $perPage;

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(a.title LIKE ? OR a.details LIKE ? OR c.company_name LIKE ? OR l.lead_name LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($type !== '' && is_valid_option($type, activity_types())) {
        $where[] = 'a.type = ?';
        $params[] = $type;
    }
    if ($clientId > 0) {
        $where[] = 'a.client_id = ?';
        $params[] = $clientId;
    }
    if ($leadId > 0) {
        $where[] = 'a.lead_id = ?';
        $params[] = $leadId;
    }
    if ($userId > 0) {
        $where[] = 'a.created_by = ?';
        $params[] = $userId;
    }
    if ($dateFrom !== '') {
        $where[] = 'a.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where[] = 'a.created_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $joins = 'FROM activities a
              LEFT JOIN clients c ON c.id = a.client_id
              LEFT JOIN leads   l ON l.id = a.lead_id
              LEFT JOIN users   u ON u.id = a.created_by';

    $countStmt = db()->prepare('SELECT COUNT(*) ' . $joins . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = 'SELECT a.*, c.company_name, l.lead_name, u.name AS owner_name ' . $joins
        . $whereSql . ' ORDER BY a.created_at DESC, a.id DESC LIMIT '
        . (int) $perPage . ' OFFSET ' . (int) $offset;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(), 'total' => $total, 'offset' => $offset];
}

function activity_find(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT a.*, c.company_name, l.lead_name, u.name AS owner_name
         FROM activities a
         LEFT JOIN clients c ON c.id = a.client_id
         LEFT JOIN leads   l ON l.id = a.lead_id
         LEFT JOIN users   u ON u.id = a.created_by
         WHERE a.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function activity_create(array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO activities (client_id, lead_id, type, title, details, created_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['client_id'] ?: null,
        $data['lead_id'] ?: null,
        $data['type'],
        $data['title'],
        null_if_empty($data['details'] ?? null),
        $data['created_by'],
    ]);
    return (int) db()->lastInsertId();
}

function activity_update(int $id, array $data): void
{
    $stmt = db()->prepare(
        'UPDATE activities SET client_id = ?, lead_id = ?, type = ?, title = ?, details = ? WHERE id = ?'
    );
    $stmt->execute([
        $data['client_id'] ?: null,
        $data['lead_id'] ?: null,
        $data['type'],
        $data['title'],
        null_if_empty($data['details'] ?? null),
        $id,
    ]);
}

function activity_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM activities WHERE id = ?');
    $stmt->execute([$id]);
}

/** History for one client (client detail page). */
function client_activities(int $clientId, int $limit = 10): array
{
    $stmt = db()->prepare(
        'SELECT a.*, u.name AS owner_name
         FROM activities a
         LEFT JOIN users u ON u.id = a.created_by
         WHERE a.client_id = ?
         ORDER BY a.created_at DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $clientId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** History for one lead (lead detail page). */
function lead_activities(int $leadId, int $limit = 10): array
{
    $stmt = db()->prepare(
        'SELECT a.*, u.name AS owner_name
         FROM activities a
         LEFT JOIN users u ON u.id = a.created_by
         WHERE a.lead_id = ?
         ORDER BY a.created_at DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $leadId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Dashboard widget: the most recent interactions across the CRM. */
function activity_recent(int $limit = 8): array
{
    $stmt = db()->prepare(
        'SELECT a.*, c.company_name, l.lead_name, l.company AS lead_company, u.name AS owner_name
         FROM activities a
         LEFT JOIN clients c ON c.id = a.client_id
         LEFT JOIN leads   l ON l.id = a.lead_id
         LEFT JOIN users   u ON u.id = a.created_by
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Count per activity type (dashboard + reports). */
function activity_count_by_type(): array
{
    return db()->query('SELECT type, COUNT(*) AS total FROM activities GROUP BY type')
        ->fetchAll(PDO::FETCH_KEY_PAIR);
}

function activity_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM activities')->fetchColumn();
}

function activity_validate(array $data): array
{
    $errors = [];

    if (($data['title'] ?? '') === '') {
        $errors['title'] = 'A short summary is required.';
    }

    if (!is_valid_option($data['type'] ?? '', activity_types())) {
        $errors['type'] = 'Choose a valid activity type.';
    }

    if ((int) ($data['client_id'] ?? 0) <= 0 && (int) ($data['lead_id'] ?? 0) <= 0) {
        $errors['client_id'] = 'Link this activity to a client or a lead.';
    }

    return $errors + length_errors([
        'title'   => [$data['title'] ?? '', 180, 'Summary'],
        'details' => [$data['details'] ?? '', 3000, 'Details'],
    ]);
}
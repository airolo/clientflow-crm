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
    $dateFrom = trim((string) ($options['date_from'] ?? ''));
    $dateTo   = trim((string) ($options['date_to'] ?? ''));

    return list_query([
        'select'  => 'a.*, c.company_name, l.lead_name, u.name AS owner_name',
        'from'    => 'FROM activities a
                      LEFT JOIN clients c ON c.id = a.client_id AND c.tenant_id = a.tenant_id
                      LEFT JOIN leads   l ON l.id = a.lead_id AND l.tenant_id = a.tenant_id
                      LEFT JOIN users   u ON u.id = a.created_by AND u.tenant_id = a.tenant_id',
        'search'  => ['a.title', 'a.details', 'c.company_name', 'l.lead_name'],
        'options' => $options,
        'filters' => [
            enum_filter('type', 'a.type', activity_types(), $options),
            id_filter('client_id', 'a.client_id', $options),
            id_filter('lead_id', 'a.lead_id', $options),
            id_filter('user_id', 'a.created_by', $options),
            // Dates are inclusive at both ends of the day.
            $dateFrom !== ''
                ? ['from', 'a.created_at >= ?', [$dateFrom . ' 00:00:00']]
                : null,
            $dateTo !== ''
                ? ['to', 'a.created_at <= ?', [$dateTo . ' 23:59:59']]
                : null,
        ],
        'order_by' => 'a.created_at DESC, a.id DESC',
        // Newest first is the only sensible order for a history feed, so no
        // sort whitelist is needed.
        'sort'        => ['recent' => 'a.created_at'],
        'sort_default' => 'recent',
        'soft_delete' => ['a'],
        'tenant'      => ['a'],
        // An activity whose client or lead has been deleted disappears with
        // it, so the history is never left dangling against a missing record.
        'where_extra' => [
            '(a.client_id IS NULL OR c.deleted_at IS NULL)',
            '(a.lead_id   IS NULL OR l.deleted_at IS NULL)',
        ],
    ]);
}

function activity_find(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT a.*, c.company_name, l.lead_name, u.name AS owner_name
         FROM activities a
         LEFT JOIN clients c ON c.id = a.client_id AND c.tenant_id = a.tenant_id
         LEFT JOIN leads   l ON l.id = a.lead_id AND l.tenant_id = a.tenant_id
         LEFT JOIN users   u ON u.id = a.created_by AND u.tenant_id = a.tenant_id
         WHERE a.tenant_id = ? AND a.id = ? AND a.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([tenant_id(), $id]);
    return $stmt->fetch() ?: null;
}

function activity_create(array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO activities (tenant_id, client_id, lead_id, type, title, details, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        tenant_id(),
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
        'UPDATE activities SET client_id = ?, lead_id = ?, type = ?, title = ?, details = ?
         WHERE tenant_id = ? AND id = ?'
    );
    $stmt->execute([
        $data['client_id'] ?: null,
        $data['lead_id'] ?: null,
        $data['type'],
        $data['title'],
        null_if_empty($data['details'] ?? null),
        tenant_id(),
        $id,
    ]);
}

function activity_delete(int $id): void
{
    soft_delete_row('activity', $id);
}

function activity_restore(int $id): void
{
    soft_delete_restore('activity', $id);
}

// A history feed reads newest first.
const ACTIVITY_RELATED_ORDER = 'r.created_at DESC';

/** History for one client (client detail page). */
function client_activities(int $clientId, int $limit = 10): array
{
    return related_list('activities', 'created_by', 'client_id', ACTIVITY_RELATED_ORDER, $clientId, $limit);
}

/** History for one lead (lead detail page). */
function lead_activities(int $leadId, int $limit = 10): array
{
    return related_list('activities', 'created_by', 'lead_id', ACTIVITY_RELATED_ORDER, $leadId, $limit);
}

/** Dashboard widget: the most recent interactions across the CRM. */
function activity_recent(int $limit = 8): array
{
    $stmt = db()->prepare(
'SELECT a.*, c.company_name, l.lead_name, l.company AS lead_company, u.name AS owner_name
         FROM activities a
         LEFT JOIN clients c ON c.id = a.client_id AND c.tenant_id = a.tenant_id
         LEFT JOIN leads   l ON l.id = a.lead_id AND l.tenant_id = a.tenant_id
         LEFT JOIN users   u ON u.id = a.created_by AND u.tenant_id = a.tenant_id
         WHERE a.tenant_id = ? AND a.deleted_at IS NULL
           AND (a.client_id IS NULL OR c.deleted_at IS NULL)
           AND (a.lead_id   IS NULL OR l.deleted_at IS NULL)
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, tenant_id(), PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Count per activity type (dashboard + reports). */
function activity_count_by_type(): array
{
    $stmt = db()->prepare('SELECT type, COUNT(*) AS total FROM activities WHERE tenant_id = ? AND deleted_at IS NULL GROUP BY type');
    $stmt->execute([tenant_id()]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function activity_count(): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM activities WHERE tenant_id = ? AND deleted_at IS NULL');
    $stmt->execute([tenant_id()]);
    return (int) $stmt->fetchColumn();
}

/**
 * Every live activity, unpaginated, for CSV export.
 *
 * @return Generator
 */
function activity_export_rows(): Generator
{
    $stmt = db()->prepare(
        "SELECT a.type, a.title, a.details, c.company_name AS client_name,
                l.lead_name, u.name AS owner_name, a.created_at
         FROM activities a
         LEFT JOIN clients c ON c.id = a.client_id AND c.tenant_id = a.tenant_id
         LEFT JOIN leads   l ON l.id = a.lead_id AND l.tenant_id = a.tenant_id
         LEFT JOIN users   u ON u.id = a.created_by AND u.tenant_id = a.tenant_id
         WHERE a.tenant_id = ? AND a.deleted_at IS NULL
         ORDER BY a.created_at DESC, a.id DESC"
    );
    $stmt->execute([tenant_id()]);
    while ($row = $stmt->fetch()) {
        yield $row;
    }
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
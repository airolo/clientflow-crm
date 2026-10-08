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
    return list_query([
        'select'  => 'l.*, u.name AS owner_name',
        'from'    => 'FROM leads l LEFT JOIN users u ON u.id = l.assigned_to AND u.tenant_id = l.tenant_id',
        'search'  => ['l.lead_name', 'l.company', 'l.email', 'l.phone'],
        'options' => $options,
        'filters' => [
            enum_filter('status', 'l.status', lead_statuses(), $options),
            enum_filter('source', 'l.lead_source', lead_sources(), $options),
            id_filter('assigned_to', 'l.assigned_to', $options),
        ],
        'sort'        => lead_sort_columns(),
        'sort_default' => 'created',
        'order_by'    => 'l.id DESC',
        'soft_delete' => ['l'],
        'tenant'      => ['l'],
    ]);
}

function lead_find(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT l.*, u.name AS owner_name, cu.name AS creator_name,
                (SELECT COUNT(*) FROM deals d WHERE d.tenant_id = l.tenant_id AND d.lead_id = l.id AND d.deleted_at IS NULL) AS deal_count
         FROM leads l
         LEFT JOIN users u  ON u.id = l.assigned_to AND u.tenant_id = l.tenant_id
         LEFT JOIN users cu ON cu.id = l.created_by AND cu.tenant_id = l.tenant_id
         WHERE l.tenant_id = ? AND l.id = ? AND l.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([tenant_id(), $id]);
    return $stmt->fetch() ?: null;
}

/** All leads as id => label, for task dropdowns. */
function lead_options(): array
{
    $stmt = db()->prepare(
        'SELECT id, lead_name, company FROM leads
         WHERE tenant_id = ? AND deleted_at IS NULL AND status NOT IN ("won","lost")
         ORDER BY lead_name ASC'
    );
    $stmt->execute([tenant_id()]);
    $rows = $stmt->fetchAll();
    $options = [];
    foreach ($rows as $row) {
        $options[$row['id']] = $row['lead_name'] . ($row['company'] ? ' (' . $row['company'] . ')' : '');
    }
    return $options;
}

function lead_create(array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO leads (tenant_id, lead_name, company, email, phone, lead_source, status, estimated_value, assigned_to, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        tenant_id(),
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
         WHERE tenant_id = ? AND id = ?'
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
        tenant_id(),
        $id,
    ]);
}

function lead_delete(int $id): void
{
    soft_delete_row('lead', $id);
}

/** Bring a deleted lead, and its deals and tasks, back. */
function lead_restore(int $id): void
{
    soft_delete_restore('lead', $id);
}

/** Move a lead to a new status (used by the quick status dropdown). */
function lead_update_status(int $id, string $status): void
{
    $stmt = db()->prepare('UPDATE leads SET status = ? WHERE tenant_id = ? AND id = ? AND deleted_at IS NULL');
    $stmt->execute([$status, tenant_id(), $id]);
}

/** Dashboard figures. */
function lead_count(): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM leads WHERE tenant_id = ? AND deleted_at IS NULL');
    $stmt->execute([tenant_id()]);
    return (int) $stmt->fetchColumn();
}

function lead_count_active(): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM leads WHERE tenant_id = ? AND deleted_at IS NULL AND status NOT IN ("won","lost")');
    $stmt->execute([tenant_id()]);
    return (int) $stmt->fetchColumn();
}

function lead_count_by_status(): array
{
    $stmt = db()->prepare('SELECT status, COUNT(*) AS total FROM leads WHERE tenant_id = ? AND deleted_at IS NULL GROUP BY status');
    $stmt->execute([tenant_id()]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function lead_count_by_source(): array
{
    $stmt = db()->prepare(
        'SELECT lead_source, COUNT(*) AS total FROM leads
         WHERE tenant_id = ? AND deleted_at IS NULL
         GROUP BY lead_source ORDER BY total DESC'
    );
    $stmt->execute([tenant_id()]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Leads that have no deal yet, i.e. not yet on the pipeline. */
function lead_unconverted(int $limit = 6): array
{
    $stmt = db()->prepare(
        'SELECT l.id, l.lead_name, l.company, l.status, l.estimated_value, u.name AS owner_name
         FROM leads l
         LEFT JOIN users u ON u.id = l.assigned_to AND u.tenant_id = l.tenant_id
         WHERE l.tenant_id = ? AND l.deleted_at IS NULL
           AND l.status NOT IN ("won","lost")
           AND NOT EXISTS (SELECT 1 FROM deals d WHERE d.tenant_id = l.tenant_id AND d.lead_id = l.id AND d.deleted_at IS NULL)
         ORDER BY l.estimated_value DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, tenant_id(), PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
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
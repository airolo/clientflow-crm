<?php
/**
 * Client model - the customer accounts.
 *
 * The list query is built with a fixed whitelist of sortable columns and a
 * parameterised search filter, so user input can never change the SQL shape.
 */

declare(strict_types=1);

/** Whitelist of sortable client columns (key => SQL expression). */
function client_sort_columns(): array
{
    return [
        'company'  => 'c.company_name',
        'contact'  => 'c.contact_person',
        'status'   => 'c.status',
        'owner'    => 'u.name',
        'created'  => 'c.created_at',
    ];
}

/**
 * Paginated, searchable, filterable client list.
 *
 * @param array $options ['search','status','assigned_to','sort','dir','page','per_page']
 * @return array ['rows' => [], 'total' => int, 'offset' => int]
 */
function client_list(array $options = []): array
{
    return list_query([
        'select'  => 'c.*, u.name AS owner_name',
        'from'    => 'FROM clients c LEFT JOIN users u ON u.id = c.assigned_to',
        'search'  => ['c.company_name', 'c.contact_person', 'c.email', 'c.phone'],
        'options' => $options,
        'filters' => [
            enum_filter('status', 'c.status', client_statuses(), $options),
            id_filter('assigned_to', 'c.assigned_to', $options),
        ],
        'sort'        => client_sort_columns(),
        'sort_default' => 'created',
        'order_by'    => 'c.id ASC',
    ]);
}

/** Single client with its owner name. */
function client_find(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT c.*, u.name AS owner_name, cu.name AS creator_name
         FROM clients c
         LEFT JOIN users u  ON u.id = c.assigned_to
         LEFT JOIN users cu ON cu.id = c.created_by
         WHERE c.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** All clients as id => label, for task dropdowns. */
function client_options(): array
{
    $rows = db()->query('SELECT id, company_name FROM clients ORDER BY company_name ASC')->fetchAll();
    $options = [];
    foreach ($rows as $row) {
        $options[$row['id']] = $row['company_name'];
    }
    return $options;
}

/** Create a client. */
function client_create(array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO clients (company_name, contact_person, email, phone, address, status, assigned_to, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['company_name'],
        $data['contact_person'],
        null_if_empty($data['email'] ?? null),
        null_if_empty($data['phone'] ?? null),
        null_if_empty($data['address'] ?? null),
        $data['status'],
        $data['assigned_to'] ?: null,
        null_if_empty($data['notes'] ?? null),
        $data['created_by'],
    ]);
    return (int) db()->lastInsertId();
}

/** Update a client. */
function client_update(int $id, array $data): void
{
    $stmt = db()->prepare(
        'UPDATE clients
         SET company_name = ?, contact_person = ?, email = ?, phone = ?, address = ?,
             status = ?, assigned_to = ?, notes = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $data['company_name'],
        $data['contact_person'],
        null_if_empty($data['email'] ?? null),
        null_if_empty($data['phone'] ?? null),
        null_if_empty($data['address'] ?? null),
        $data['status'],
        $data['assigned_to'] ?: null,
        null_if_empty($data['notes'] ?? null),
        $id,
    ]);
}

function client_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM clients WHERE id = ?');
    $stmt->execute([$id]);
}

/** Dashboard counts. */
function client_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM clients')->fetchColumn();
}

function client_count_by_status(): array
{
    return db()->query('SELECT status, COUNT(*) AS total FROM clients GROUP BY status')
        ->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Deal totals for one client. */
function client_deal_summary(int $clientId): array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS deal_count, COALESCE(SUM(value), 0) AS total_value
         FROM deals WHERE client_id = ?'
    );
    $stmt->execute([$clientId]);
    $row = $stmt->fetch() ?: ['deal_count' => 0, 'total_value' => 0];
    $row['deal_count'] = (int) $row['deal_count'];
    $row['total_value'] = (float) $row['total_value'];
    return $row;
}

/** Deals attached to a client (used on the client detail page). */
function client_deals(int $clientId): array
{
    $stmt = db()->prepare(
        'SELECT d.*, u.name AS owner_name
         FROM deals d
         LEFT JOIN users u ON u.id = d.assigned_to
         WHERE d.client_id = ?
         ORDER BY FIELD(d.stage, "new_lead","contacted","proposal","negotiation","won","lost"), d.value DESC'
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll();
}

/**
 * Validate a client form. Returns an array of field => error message.
 *
 * Length limits mirror the VARCHAR widths in database.sql - see length_errors().
 */
function client_validate(array $data): array
{
    $errors = [];

    if (($data['company_name'] ?? '') === '') {
        $errors['company_name'] = 'Company name is required.';
    }

    if (($data['contact_person'] ?? '') === '') {
        $errors['contact_person'] = 'Contact person is required.';
    }

    $email = $data['email'] ?? '';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if (!is_valid_option($data['status'] ?? '', client_statuses())) {
        $errors['status'] = 'Choose a valid status.';
    }

    // Existing errors win, so an empty field reports "required" not "too long".
    return $errors + length_errors([
        'company_name'   => [$data['company_name'] ?? '', 150, 'Company name'],
        'contact_person' => [$data['contact_person'] ?? '', 120, 'Contact person'],
        'email'          => [$email, 150, 'Email'],
        'phone'          => [$data['phone'] ?? '', 40, 'Phone'],
        'address'        => [$data['address'] ?? '', 255, 'Address'],
        'notes'          => [$data['notes'] ?? '', 2000, 'Notes'],
    ]);
}
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
        'from'    => 'FROM clients c LEFT JOIN users u ON u.id = c.assigned_to AND u.tenant_id = c.tenant_id',
        'search'  => ['c.company_name', 'c.contact_person', 'c.email', 'c.phone'],
        'options' => $options,
        'filters' => [
            enum_filter('status', 'c.status', client_statuses(), $options),
            id_filter('assigned_to', 'c.assigned_to', $options),
        ],
        'sort'        => client_sort_columns(),
        'sort_default' => 'created',
        'order_by'    => 'c.id ASC',
        'soft_delete' => ['c'],
        'tenant'      => ['c'],
    ]);
}

/** Single client with its owner name. */
function client_find(int $id): ?array
{
    $stmt = db()->prepare(
'SELECT c.*, u.name AS owner_name, cu.name AS creator_name
         FROM clients c
         LEFT JOIN users u  ON u.id = c.assigned_to AND u.tenant_id = c.tenant_id
         LEFT JOIN users cu ON cu.id = c.created_by AND cu.tenant_id = c.tenant_id
         WHERE c.tenant_id = ? AND c.id = ? AND c.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([tenant_id(), $id]);
    return $stmt->fetch() ?: null;
}

/** Every client in this workspace, as id => label, for task dropdowns. */
function client_options(): array
{
    $stmt = db()->prepare(
        'SELECT id, company_name FROM clients
         WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY company_name ASC'
    );
    $stmt->execute([tenant_id()]);
    $rows = $stmt->fetchAll();
    $options = [];
    foreach ($rows as $row) {
        $options[$row['id']] = $row['company_name'];
    }
    return $options;
}

/**
 * Every live client, unpaginated, for CSV export.
 *
 * A generator rather than a returned array so a large workspace streams to the
 * download instead of being held in memory twice - once as rows, once as the
 * accumulated CSV. fetch() in a loop rather than fetchAll() for the same reason.
 *
 * Soft-deleted rows are excluded, matching what the list screen shows. Deleted
 * records live in the recycle bin, and an export that silently included them
 * would put records someone deliberately removed back into a file they were
 * about to email to someone.
 *
 * The owner name is joined in and scoped on tenant_id because user ids are only
 * unique per workspace.
 *
 * @return Generator
 */
function client_export_rows(): Generator
{
    $stmt = db()->prepare(
        "SELECT c.company_name, c.contact_person, c.email, c.phone, c.address,
                c.status, u.name AS owner_name, c.notes, c.created_at
         FROM clients c
         LEFT JOIN users u ON u.id = c.assigned_to AND u.tenant_id = c.tenant_id
         WHERE c.tenant_id = ? AND c.deleted_at IS NULL
         ORDER BY c.company_name ASC, c.id ASC"
    );
    $stmt->execute([tenant_id()]);
    while ($row = $stmt->fetch()) {
        yield $row;
    }
}

/** Create a client. */
function client_create(array $data): int
{
    // tenant_id comes from the session, never from $data: a crafted form must
    // not be able to file a record into another business.
    $stmt = db()->prepare(
        'INSERT INTO clients (tenant_id, company_name, contact_person, email, phone, address, status, assigned_to, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        tenant_id(),
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
         WHERE tenant_id = ? AND id = ?'
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
        tenant_id(),
        $id,
    ]);
}

/**
 * Delete a client by stamping it. The deals, tasks and activities that hang
 * off it are left untouched and become invisible with it, so restoring is a
 * single UPDATE and cannot lose or duplicate a child row.
 */
function client_delete(int $id): void
{
    soft_delete_row('client', $id);
}

/** Bring a deleted client, and everything attached to it, back. */
function client_restore(int $id): void
{
    soft_delete_restore('client', $id);
}

/** Dashboard counts. */
function client_count(): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM clients WHERE tenant_id = ? AND deleted_at IS NULL');
    $stmt->execute([tenant_id()]);
    return (int) $stmt->fetchColumn();
}

function client_count_by_status(): array
{
    $stmt = db()->prepare(
        'SELECT status, COUNT(*) AS total FROM clients
         WHERE tenant_id = ? AND deleted_at IS NULL GROUP BY status'
    );
    $stmt->execute([tenant_id()]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Deal totals for one client. */
function client_deal_summary(int $clientId): array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS deal_count, COALESCE(SUM(value), 0) AS total_value
         FROM deals WHERE tenant_id = ? AND client_id = ? AND deleted_at IS NULL'
    );
    $stmt->execute([tenant_id(), $clientId]);
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
         LEFT JOIN users u ON u.id = d.assigned_to AND u.tenant_id = d.tenant_id
         WHERE d.tenant_id = ? AND d.client_id = ? AND d.deleted_at IS NULL
         ORDER BY FIELD(d.stage, "new_lead","contacted","proposal","negotiation","won","lost"), d.value DESC'
    );
    $stmt->execute([tenant_id(), $clientId]);
    return $stmt->fetchAll();
}

/** How many live clients are assigned to one user (profile page). */
function client_count_assigned_to(int $userId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM clients WHERE tenant_id = ? AND assigned_to = ? AND deleted_at IS NULL'
    );
    $stmt->execute([tenant_id(), $userId]);
    return (int) $stmt->fetchColumn();
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
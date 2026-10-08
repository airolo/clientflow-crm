<?php
/**
 * User model - login accounts and the staff list shown in dropdowns.
 */

declare(strict_types=1);

/**
 * Look up a user by email within one workspace.
 *
 * Scoped on purpose: email is unique per tenant, not globally, so an unscoped
 * lookup could return a different business's account for the same address.
 */
function user_find_by_email(string $email, ?int $tenantId = null): ?array
{
    $tenantId ??= tenant_id();
    $stmt = db()->prepare('SELECT * FROM users WHERE tenant_id = ? AND email = ? LIMIT 1');
    $stmt->execute([$tenantId, $email]);
    return $stmt->fetch() ?: null;
}

/** Look up a user by id, within the signed-in tenant. */
function user_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE tenant_id = ? AND id = ? LIMIT 1');
    $stmt->execute([tenant_id(), $id]);
    return $stmt->fetch() ?: null;
}

/**
 * Look up a user by id in a specific tenant, for the few places that resolve
 * an account before a session exists. Almost everything should use user_find().
 */
function user_find_in_tenant(int $id, int $tenantId): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE tenant_id = ? AND id = ? LIMIT 1');
    $stmt->execute([$tenantId, $id]);
    return $stmt->fetch() ?: null;
}

/**
 * Paginated, searchable, filterable user list.
 *
 * @param array $options ['search','role','sort','dir','page']
 * @return array ['rows' => [], 'total' => int, 'offset' => int]
 */
function user_list(array $options = []): array
{
    $role = (string) ($options['role'] ?? '');
    $validRole = in_array($role, ['admin', 'staff'], true);

    return list_query([
        'select'  => 'u.id, u.name, u.email, u.role, u.phone, u.is_active, u.must_change_password, u.created_at',
        'from'    => 'FROM users u',
        'search'  => ['u.name', 'u.email'],
        'options' => $options,
        'filters' => [
            $validRole ? ['role', 'u.role = ?', [$role]] : null,
        ],
        // Admins first, then alphabetical. Not user-sortable on purpose: the
        // order is meaningful rather than a column choice.
        'sort'         => ['role_name' => 'u.role'],
        'sort_default' => 'role_name',
        'order_by'     => 'u.role = "admin" DESC, u.name ASC',
        // Users are never soft-deleted: a login account is deactivated with
        // is_active = 0 or removed outright, and the table has no deleted_at.
        'soft_delete'  => [],
        // The alias here is u, not the list_query default of t, so the tenant
        // predicate has to be named explicitly.
        'tenant'       => ['u'],
    ]);
}

/** All users in this workspace, active first. */
function user_all(bool $activeOnly = true): array
{
    $sql = 'SELECT id, name, email, role, phone, is_active, created_at
            FROM users WHERE tenant_id = ?'
        . ($activeOnly ? ' AND is_active = 1' : '') . '
            ORDER BY role = "admin" DESC, name ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute([tenant_id()]);
    return $stmt->fetchAll();
}

/** Simple id => name list for <select> dropdowns. */
function user_options(): array
{
    $users = user_all(true);
    $options = [];
    foreach ($users as $user) {
        $options[$user['id']] = $user['name'] . ' (' . ucfirst($user['role']) . ')';
    }
    return $options;
}

/** Create a user in the signed-in tenant; the password is hashed here. */
function user_create(array $data): int
{
    // An admin can choose to have the new user set their own password on first
    // sign-in, rather than handing over a password they will both know.
    $mustChange = !empty($data['must_change_password']) ? 1 : 0;

    // Always the signed-in tenant, never $data. Accepting a tenant_id here
    // would let a crafted form create an account inside another business.
    $stmt = db()->prepare(
        'INSERT INTO users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        tenant_id(),
        $data['name'],
        $data['email'],
        password_hash($data['password'], PASSWORD_DEFAULT),
        $data['role'],
        null_if_empty($data['phone'] ?? null),
        !empty($data['is_active']) ? 1 : 0,
        $mustChange,
    ]);
    return (int) db()->lastInsertId();
}

/** Update profile fields (name, email, phone). */
function user_update_profile(int $id, array $data): void
{
    $stmt = db()->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE tenant_id = ? AND id = ?');
    $stmt->execute([$data['name'], $data['email'], null_if_empty($data['phone'] ?? null), tenant_id(), $id]);
}

/** Change a password after verifying the current one. */
function user_update_password(int $id, string $newPassword): void
{
    $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE tenant_id = ? AND id = ?');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), tenant_id(), $id]);
}

/**
 * Set a password directly and clear the forced-change flag.
 *
 * Used by the forced-change screen, which has already verified the old
 * password, and by an admin resetting someone's password.
 */
function user_set_password(int $id, string $newPassword, bool $clearFlag = false): void
{
    $sql = 'UPDATE users SET password_hash = ?' . ($clearFlag ? ', must_change_password = 0' : '') . ' WHERE tenant_id = ? AND id = ?';
    $stmt = db()->prepare($sql);
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), tenant_id(), $id]);
}

/** True when the account still needs its password changed before continuing. */
function user_must_change_password(int $id): bool
{
    $stmt = db()->prepare('SELECT must_change_password FROM users WHERE tenant_id = ? AND id = ? LIMIT 1');
    $stmt->execute([tenant_id(), $id]);
    return (int) $stmt->fetchColumn() === 1;
}

/**
 * Admin-only: change role / active flag / password reset.
 *
 * Supplying a password implicitly sets must_change_password, because an admin
 * who resets someone's password is telling them to choose their own.
 */
function user_update_admin(int $id, array $data): void
{
    $fields = [
        'name'      => $data['name'],
        'email'     => $data['email'],
        'role'      => $data['role'],
        'phone'     => null_if_empty($data['phone'] ?? null),
        'is_active' => !empty($data['is_active']) ? 1 : 0,
    ];
    $sql = 'UPDATE users SET name = ?, email = ?, role = ?, phone = ?, is_active = ?, must_change_password = ? WHERE tenant_id = ? AND id = ?';
    $params = array_values($fields);
    $params[] = !empty($data['password']) ? 1 : 0;
    $params[] = tenant_id();
    $params[] = $id;

    if (!empty($data['password'])) {
        $sql = 'UPDATE users SET name = ?, email = ?, role = ?, phone = ?, is_active = ?, must_change_password = 1, password_hash = ? WHERE tenant_id = ? AND id = ?';
        $params = array_merge(array_values($fields), [password_hash($data['password'], PASSWORD_DEFAULT), tenant_id(), $id]);
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
}

function user_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM users WHERE tenant_id = ? AND id = ?');
    $stmt->execute([tenant_id(), $id]);
}

/**
 * True when the email is already used by another account in this workspace.
 *
 * Scoped to the tenant, matching the (tenant_id, email) unique index. A
 * global check here would wrongly reject an address that another business owns.
 */
function user_email_exists(string $email, ?int $exceptId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM users WHERE tenant_id = ? AND email = ?';
    $params = [tenant_id(), $email];
    if ($exceptId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Count this workspace's admins, so its last one cannot be removed or demoted.
 *
 * Counted per tenant. A global count would let one business's admin activity
 * decide whether another business could demote its own last admin.
 */
function user_admin_count(?int $tenantId = null): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = "admin" AND is_active = 1'
    );
    $stmt->execute([$tenantId ?? tenant_id()]);
    return (int) $stmt->fetchColumn();
}

/** Dashboard figure: how many records each user in this workspace owns. */
function user_workload(): array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.name, u.role,
                (SELECT COUNT(*) FROM clients c WHERE c.tenant_id = u.tenant_id AND c.assigned_to = u.id AND c.deleted_at IS NULL) AS client_count,
                (SELECT COUNT(*) FROM leads l   WHERE l.tenant_id = u.tenant_id AND l.assigned_to = u.id AND l.deleted_at IS NULL) AS lead_count,
                (SELECT COUNT(*) FROM deals d   WHERE d.tenant_id = u.tenant_id AND d.assigned_to = u.id AND d.deleted_at IS NULL AND d.stage IN ("new_lead","contacted","proposal","negotiation")) AS open_deals,
                (SELECT COUNT(*) FROM tasks t   WHERE t.tenant_id = u.tenant_id AND t.assigned_to = u.id AND t.deleted_at IS NULL AND t.status <> "completed") AS open_tasks
         FROM users u
         WHERE u.tenant_id = ? AND u.is_active = 1
         ORDER BY open_deals DESC, u.name ASC'
    );
    $stmt->execute([tenant_id()]);
    return $stmt->fetchAll();
}
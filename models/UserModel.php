<?php
/**
 * User model - login accounts and the staff list shown in dropdowns.
 */

declare(strict_types=1);

/** Look up a user by email (used by the login form). */
function user_find_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    return $stmt->fetch() ?: null;
}

/** Look up a user by id. */
function user_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** All users, active first. */
function user_all(bool $activeOnly = true): array
{
    $sql = 'SELECT id, name, email, role, phone, is_active, created_at
            FROM users' . ($activeOnly ? ' WHERE is_active = 1' : '') . '
            ORDER BY role = "admin" DESC, name ASC';
    return db()->query($sql)->fetchAll();
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

/** Create a user; the password is hashed here. */
function user_create(array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO users (name, email, password_hash, role, phone, is_active)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['name'],
        $data['email'],
        password_hash($data['password'], PASSWORD_DEFAULT),
        $data['role'],
        null_if_empty($data['phone'] ?? null),
        !empty($data['is_active']) ? 1 : 0,
    ]);
    return (int) db()->lastInsertId();
}

/** Update profile fields (name, email, phone). */
function user_update_profile(int $id, array $data): void
{
    $stmt = db()->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?');
    $stmt->execute([$data['name'], $data['email'], null_if_empty($data['phone'] ?? null), $id]);
}

/** Change a password after verifying the current one. */
function user_update_password(int $id, string $newPassword): void
{
    $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);
}

/** Admin-only: change role / active flag / password reset. */
function user_update_admin(int $id, array $data): void
{
    $fields = [
        'name'      => $data['name'],
        'email'     => $data['email'],
        'role'      => $data['role'],
        'phone'     => null_if_empty($data['phone'] ?? null),
        'is_active' => !empty($data['is_active']) ? 1 : 0,
    ];
    $sql = 'UPDATE users SET name = ?, email = ?, role = ?, phone = ?, is_active = ? WHERE id = ?';
    $params = array_values($fields);
    $params[] = $id;

    if (!empty($data['password'])) {
        $sql = 'UPDATE users SET name = ?, email = ?, role = ?, phone = ?, is_active = ?, password_hash = ? WHERE id = ?';
        $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        $params[] = $id;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
}

function user_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
}

/** True when the email is already used by another account. */
function user_email_exists(string $email, ?int $exceptId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM users WHERE email = ?';
    $params = [$email];
    if ($exceptId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

/** Count admins so the last one cannot be removed or demoted. */
function user_admin_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "admin" AND is_active = 1')->fetchColumn();
}

/** Dashboard figure: how many records each user owns. */
function user_workload(): array
{
    return db()->query(
        'SELECT u.id, u.name, u.role,
                (SELECT COUNT(*) FROM clients c WHERE c.assigned_to = u.id) AS client_count,
                (SELECT COUNT(*) FROM leads l   WHERE l.assigned_to = u.id) AS lead_count,
                (SELECT COUNT(*) FROM deals d   WHERE d.assigned_to = u.id AND d.stage IN ("new_lead","contacted","proposal","negotiation")) AS open_deals,
                (SELECT COUNT(*) FROM tasks t   WHERE t.assigned_to = u.id AND t.status <> "completed") AS open_tasks
         FROM users u
         WHERE u.is_active = 1
         ORDER BY open_deals DESC, u.name ASC'
    )->fetchAll();
}
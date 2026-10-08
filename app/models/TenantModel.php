<?php
/**
 * Tenant model - the businesses using the CRM.
 */

declare(strict_types=1);

/** Slugs must be usable in a URL and typed on a phone. */
function tenant_slug_is_valid(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{1,58}[a-z0-9])?$/', $slug);
}

/**
 * Turn a business name into a candidate slug.
 *
 * Lowercased, spaces and punctuation collapsed to single hyphens. Used to
 * suggest a workspace URL, not to enforce it.
 */
function tenant_slug_from_name(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');

    // Trim to 60 characters on a hyphen boundary so it never ends mid-word.
    if (strlen($slug) > 60) {
        $slug = substr($slug, 0, 60);
        $cut  = strrpos($slug, '-');
        $slug = $cut === false ? $slug : substr($slug, 0, $cut);
    }

    // A slug must start and end with an alphanumeric, so a single leading
    // character cannot be stripped to nothing.
    return $slug !== '' ? $slug : 'workspace';
}

function tenant_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Look up a workspace by its slug. This is how sign-in resolves a business. */
function tenant_find_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM tenants WHERE slug = ? LIMIT 1');
    $stmt->execute([strtolower(trim($slug))]);
    return $stmt->fetch() ?: null;
}

/** Every workspace, for the ops console. */
function tenant_all(): array
{
    return db()->query(
        'SELECT t.*,
                (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS user_count,
                (SELECT COUNT(*) FROM clients c WHERE c.tenant_id = t.id AND c.deleted_at IS NULL) AS client_count
         FROM tenants t
         ORDER BY t.created_at ASC'
    )->fetchAll();
}

function tenant_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
}

/** True when the slug is already taken. */
function tenant_slug_taken(string $slug, ?int $exceptId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM tenants WHERE slug = ?';
    $params = [$slug];
    if ($exceptId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Create a workspace.
 *
 * @param array $data name, slug, currency, timezone
 */
function tenant_create(array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO tenants (name, slug, plan, status, currency, timezone)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['name'],
        $data['slug'],
        $data['plan'] ?? 'trial',
        $data['status'] ?? 'active',
        $data['currency'] ?? 'GBP',
        $data['timezone'] ?? 'Europe/London',
    ]);
    return (int) db()->lastInsertId();
}

/**
 * Create a workspace and its first admin together.
 *
 * Both or neither: a tenant with no admin, or an admin with no tenant, would
 * leave an installation that cannot be signed into. Used by the signup flow.
 *
 * @return array ['tenant_id' => int, 'user_id' => int]
 */
function tenant_create_with_owner(array $tenant, array $owner): array
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $tenantId = tenant_create($tenant);

        $stmt = $pdo->prepare(
            'INSERT INTO users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
             VALUES (?, ?, ?, ?, ?, ?, 1, 0)'
        );
        $stmt->execute([
            $tenantId,
            $owner['name'],
            $owner['email'],
            password_hash($owner['password'], PASSWORD_DEFAULT),
            'admin',
            null_if_empty($owner['phone'] ?? null),
        ]);
        $userId = (int) $pdo->lastInsertId();

        $pdo->commit();
        return ['tenant_id' => $tenantId, 'user_id' => $userId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Suspend or restore a workspace. A suspended tenant cannot sign in. */
function tenant_set_status(int $id, string $status): void
{
    $stmt = db()->prepare('UPDATE tenants SET status = ? WHERE id = ?');
    $stmt->execute([$status === 'suspended' ? 'suspended' : 'active', $id]);
}

function tenant_set_plan(int $id, string $plan): void
{
    $stmt = db()->prepare('UPDATE tenants SET plan = ? WHERE id = ?');
    $stmt->execute([$plan, $id]);
}

/** Update the settings that vary per business. */
function tenant_update_settings(int $id, array $data): void
{
    $stmt = db()->prepare('UPDATE tenants SET name = ?, currency = ?, timezone = ? WHERE id = ?');
    $stmt->execute([$data['name'], $data['currency'], $data['timezone'], $id]);
}

/** Supported currencies, so the settings form is a list rather than free text. */
function tenant_currencies(): array
{
    return [
        'GBP' => '£ Pound sterling',
        'EUR' => '€ Euro',
        'USD' => '$ US dollar',
        'CAD' => 'C$ Canadian dollar',
        'AUD' => 'A$ Australian dollar',
        'NZD' => 'NZ$ New Zealand dollar',
        'CHF' => 'CHF Swiss franc',
        'SEK' => 'kr Swedish krona',
        'NOK' => 'kr Norwegian krone',
        'DKK' => 'kr Danish krone',
        'PLN' => 'zł Polish złoty',
        'INR' => '₹ Indian rupee',
        'JPY' => '¥ Japanese yen',
        'ZAR' => 'R South African rand',
        'BRL' => 'R$ Brazilian real',
        'MXN' => 'Mexican peso',
        'SGD' => 'S$ Singapore dollar',
        'HKD' => 'HK$ Hong Kong dollar',
        'AED' => 'د.إ UAE dirham',
    ];
}
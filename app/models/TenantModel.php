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

/** How many staff accounts this workspace has. */
function tenant_user_count(?int $tenantId = null): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE tenant_id = ?');
    $stmt->execute([$tenantId ?? tenant_id()]);
    return (int) $stmt->fetchColumn();
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
    $status = $status === 'suspended' ? 'suspended' : 'active';
    $before = tenant_find($id);

    $stmt = db()->prepare('UPDATE tenants SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);

    // Suspending a workspace is the most consequential action in the app, so it
    // is recorded in that workspace's own log, addressed explicitly rather than
    // through the session. An operator running this from the platform console has
    // no session tenant, and a log row filed under the wrong workspace would be
    // worse than none.
    if ($before !== null && $before['status'] !== $status) {
        audit_record(
            $status === 'suspended' ? 'suspend' : 'activate',
            'tenant',
            $id,
            (string) $before['name'],
            [['field' => 'status', 'label' => 'Workspace status', 'from' => (string) $before['status'], 'to' => $status]],
            null,
            $id,
            'Platform'
        );
    }
}

function tenant_set_plan(int $id, string $plan): void
{
    $stmt = db()->prepare('UPDATE tenants SET plan = ? WHERE id = ?');
    $stmt->execute([$plan, $id]);
}

/** Update the settings that vary per business. */
function tenant_update_settings(int $id, array $data): void
{
    // Read first so the log can show currency and timezone changing. Both are
    // workspace-wide settings, so which ones were altered is worth recording.
    $before = tenant_find($id);

    $stmt = db()->prepare('UPDATE tenants SET name = ?, currency = ?, timezone = ? WHERE id = ?');
    $stmt->execute([$data['name'], $data['currency'], $data['timezone'], $id]);

    if ($before !== null) {
        $fields = ['name' => 'Business name', 'currency' => 'Currency', 'timezone' => 'Timezone'];
        // array_merge, not `$before + [...]`: + keeps the left operand's value
        // for a key it already has, so the new settings would be discarded and
        // the diff would come back empty.
        $after = array_merge($before, [
            'name'     => $data['name'],
            'currency' => $data['currency'],
            'timezone' => $data['timezone'],
        ]);
        audit_record_update('tenant', $id, $before, $after, $fields, (string) $data['name']);
    }
}

/**
 * Supported currencies, as code => symbol + name.
 *
 * The symbol is stored explicitly rather than being taken from the first
 * character of the name, because three of these share one: SEK, NOK and DKK are
 * all "kr", so a name-derived symbol would silently label Swedish krona as
 * Norwegian. Storing them separately means a new currency is one array entry
 * rather than a naming convention to respect.
 *
 * Ordered by the symbol so the dropdown groups roughly by region.
 */
function tenant_currencies(): array
{
    return [
        'GBP' => ['symbol' => '£',  'name' => 'Pound sterling'],
        'EUR' => ['symbol' => '€',  'name' => 'Euro'],
        'USD' => ['symbol' => '$',  'name' => 'US dollar'],
        'CAD' => ['symbol' => 'C$', 'name' => 'Canadian dollar'],
        'AUD' => ['symbol' => 'A$', 'name' => 'Australian dollar'],
        'NZD' => ['symbol' => 'NZ$', 'name' => 'New Zealand dollar'],
        'CHF' => ['symbol' => 'CHF', 'name' => 'Swiss franc'],
        'SEK' => ['symbol' => 'kr', 'name' => 'Swedish krona'],
        'NOK' => ['symbol' => 'kr', 'name' => 'Norwegian krone'],
        'DKK' => ['symbol' => 'kr', 'name' => 'Danish krone'],
        'PLN' => ['symbol' => 'zł', 'name' => 'Polish zloty'],
        'INR' => ['symbol' => '₹',  'name' => 'Indian rupee'],
        'JPY' => ['symbol' => '¥',  'name' => 'Japanese yen'],
        'ZAR' => ['symbol' => 'R',  'name' => 'South African rand'],
        'BRL' => ['symbol' => 'R$', 'name' => 'Brazilian real'],
        'MXN' => ['symbol' => 'MX$', 'name' => 'Mexican peso'],
        'SGD' => ['symbol' => 'S$', 'name' => 'Singapore dollar'],
        'HKD' => ['symbol' => 'HK$', 'name' => 'Hong Kong dollar'],
        'AED' => ['symbol' => 'د.إ', 'name' => 'UAE dirham'],
    ];
}

/** True when $code is a currency the settings form offers. */
function tenant_currency_is_valid(string $code): bool
{
    return array_key_exists($code, tenant_currencies());
}

/**
 * The symbol to print for a given currency code.
 *
 * Falls back to the code itself. An unknown code rendering as "£" would show the
 * wrong money symbol on every invoice figure, which is worse than showing
 * "XYZ 1,200.00" and obviously wrong.
 *
 * Takes the code explicitly; tenant_currency_symbol() in app/tenancy.php is the
 * no-argument version that reads the signed-in workspace.
 */
function tenant_currency_symbol_for(string $code): string
{
    $currencies = tenant_currencies();
    return $currencies[$code]['symbol'] ?? $code;
}

/**
 * Timezones offered in the settings form.
 *
 * A curated shortlist rather than DateTimeZone::listIdentifiers(), which is
 * around 400 entries of which a small business needs a handful. Validation does
 * not use this list, though - it accepts any identifier PHP recognises, so a
 * workspace in a region nobody thought of is not blocked from setting its own
 * zone.
 */
function tenant_timezones(): array
{
    return [
        'Europe/London'        => 'London (GMT/BST)',
        'Europe/Dublin'        => 'Dublin',
        'Europe/Lisbon'        => 'Lisbon',
        'Europe/Madrid'        => 'Madrid',
        'Europe/Paris'         => 'Paris',
        'Europe/Brussels'      => 'Brussels',
        'Europe/Amsterdam'     => 'Amsterdam',
        'Europe/Berlin'        => 'Berlin',
        'Europe/Zurich'        => 'Zurich',
        'Europe/Rome'          => 'Rome',
        'Europe/Prague'        => 'Prague',
        'Europe/Warsaw'        => 'Warsaw',
        'Europe/Stockholm'     => 'Stockholm',
        'Europe/Oslo'          => 'Oslo',
        'Europe/Copenhagen'    => 'Copenhagen',
        'Europe/Helsinki'      => 'Helsinki',
        'Europe/Athens'        => 'Athens',
        'Europe/Istanbul'      => 'Istanbul',
        'Europe/Moscow'        => 'Moscow',
        'Atlantic/Reykjavik'   => 'Reykjavik',
        'America/New_York'     => 'New York',
        'America/Chicago'      => 'Chicago',
        'America/Denver'       => 'Denver',
        'America/Los_Angeles'  => 'Los Angeles',
        'America/Vancouver'    => 'Vancouver',
        'America/Toronto'      => 'Toronto',
        'America/Mexico_City'  => 'Mexico City',
        'America/Bogota'       => 'Bogota',
        'America/Sao_Paulo'    => 'São Paulo',
        'America/Argentina/Buenos_Aires' => 'Buenos Aires',
        'Africa/Lagos'         => 'Lagos',
        'Africa/Cairo'         => 'Cairo',
        'Africa/Johannesburg'  => 'Johannesburg',
        'Africa/Nairobi'       => 'Nairobi',
        'Asia/Dubai'           => 'Dubai',
        'Asia/Karachi'         => 'Karachi',
        'Asia/Kolkata'         => 'Kolkata',
        'Asia/Singapore'       => 'Singapore',
        'Asia/Bangkok'         => 'Bangkok',
        'Asia/Jakarta'         => 'Jakarta',
        'Asia/Hong_Kong'       => 'Hong Kong',
        'Asia/Shanghai'        => 'Shanghai',
        'Asia/Tokyo'           => 'Tokyo',
        'Asia/Seoul'           => 'Seoul',
        'Australia/Perth'      => 'Perth',
        'Australia/Adelaide'   => 'Adelaide',
        'Australia/Sydney'     => 'Sydney',
        'Australia/Brisbane'   => 'Brisbane',
        'Pacific/Auckland'     => 'Auckland',
        'UTC'                  => 'UTC',
    ];
}

/**
 * True when $tz is a timezone identifier PHP recognises.
 *
 * Deliberately checks PHP's own list rather than tenant_timezones(), so the
 * shortlist being curated does not become a restriction on what a workspace may
 * set.
 */
function tenant_timezone_is_valid(string $tz): bool
{
    return $tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true);
}
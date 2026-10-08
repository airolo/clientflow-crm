<?php
/**
 * Authentication + session handling (the app's middleware layer).
 */

declare(strict_types=1);

/**
 * Start a hardened session. Called once from bootstrap.php.
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,       // JavaScript cannot read the session cookie
        'secure'   => $isHttps,   // HTTPS only when served over HTTPS
        'samesite' => 'Lax',      // basic CSRF defence in depth
    ]);

    session_name('CLIENTFLOWSESSID');
    session_start();

    // Rotate the session id periodically to limit session-fixation windows.
    if (!isset($_SESSION['_created_at'])) {
        $_SESSION['_created_at'] = time();
    } elseif (time() - (int) $_SESSION['_created_at'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_created_at'] = time();
    }

    // Idle timeout after 2 hours of inactivity.
    if (isset($_SESSION['_last_activity']) && (time() - (int) $_SESSION['_last_activity'] > 7200)) {
        logout_user();
        flash('warning', 'You were signed out after a period of inactivity.');
        redirect('login.php');
    }
    $_SESSION['_last_activity'] = time();
}

/** True when someone is signed in. */
function is_logged_in(): bool
{
    return !empty($_SESSION['user']['id']);
}

/** The signed-in user array (id, name, email, role) or null. */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/** Shorthand for the signed-in user's id. */
function current_user_id(): ?int
{
    return current_user()['id'] ?? null;
}

/** True when the signed-in user has the admin role. */
function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

/**
 * Put a verified user into the session.
 *
 * Shared by sign-in and by signup, so the session shape lives in one place. Both
 * callers must have already established that the password is correct - this
 * function does no checking of its own, and takes the row it was handed rather
 * than re-reading it.
 *
 * The tenant id is a required argument rather than read from the user row's
 * nullable columns, because it is the one value that decides what every later
 * query in the request can see.
 */
function sign_in_user(array $user, int $tenantId): void
{
    // New session id on privilege change (session fixation protection).
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'    => (int) $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ];
    // Every query filters on this. Taken from the row we just verified, never
    // from the request.
    $_SESSION['tenant_id'] = $tenantId;
    tenant_cache_reset();
    // Carried from the database rather than trusted from the form, so the
    // forced-change redirect cannot be bypassed by posting a different value.
    $_SESSION['must_change_password'] = (int) ($user['must_change_password'] ?? 0) === 1;
    $_SESSION['_created_at'] = time();
    $_SESSION['_last_activity'] = time();
}

/**
 * Attempt a login. Returns an error string on failure, or null on success.
 *
 * Takes the workspace slug as well as the email, because two businesses may
 * legitimately both have an admin@company.com and their accounts are separate.
 */
function attempt_login(string $tenantSlug, string $email, string $password): ?string
{
    $ip     = client_ip();
    $tenant = tenant_find_by_slug($tenantSlug);

    // Throttling is checked before any hash comparison, so a run of guesses
    // against one account or from one address stops being expensive. Keyed on
    // the resolved tenant, or null when the workspace is unknown.
    $tenantId = $tenant ? (int) $tenant['id'] : null;
    if (login_attempt_locked_out($tenantId, $email, $ip)) {
        $minutes = max(1, (int) ceil(login_attempt_retry_seconds($tenantId, $email) / 60));
        return 'Too many failed sign-in attempts. Try again in about '
            . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
    }

    if (!$tenant) {
        // Recorded against no tenant. The message is deliberately the same one
        // used for a wrong password, so the form cannot be used to discover
        // which workspace slugs exist.
        login_attempt_record(null, $email, false);
        login_attempt_prune();
        return 'Those credentials do not match our records.';
    }

    if ($tenant['status'] !== 'active') {
        return 'This workspace has been suspended. Please contact support.';
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE tenant_id = ? AND email = ? LIMIT 1');
    $stmt->execute([(int) $tenant['id'], $email]);
    $user = $stmt->fetch();

    // Same generic message for unknown email and wrong password.
    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        login_attempt_record($tenantId, $email, false);
        login_attempt_prune();
        return 'Those credentials do not match our records.';
    }
    if ((int) $user['is_active'] !== 1) {
        // Recorded, but the failure counter is deliberately not incremented:
        // the password was correct, and an attacker should not be able to use
        // this path to lock a real account out of its own login.
        login_attempt_record($tenantId, $email, false);
        return 'This account has been deactivated. Please contact an administrator.';
    }

    login_attempt_record($tenantId, $email, true);
    login_attempt_clear($tenantId, $email);

    sign_in_user($user, (int) $tenant['id']);

    return null;
}

/** True when the signed-in account must set a new password before continuing. */
function must_change_password(): bool
{
    return !empty($_SESSION['must_change_password']);
}

/** Clear session data and the auth cookie. */
function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

/**
 * Auth guard: every authenticated page calls this at the top.
 *
 * Also enforces the forced password change. Accounts seeded with published
 * passwords, and accounts an admin has reset, are held on
 * auth/change_password.php until the password is replaced.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        flash('warning', 'Please sign in to continue.');
        redirect('login.php');
    }

    // Re-checks the workspace still exists and is not suspended. A tenant
    // suspended mid-session is signed out here rather than kept working until
    // the idle timeout.
    require_active_tenant();

    if (must_change_password()) {
        $here = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($here !== 'change_password.php' && $here !== 'logout.php') {
            redirect('auth/change_password.php');
        }
    }
}

/**
 * Role guard: restricts a page to administrators.
 */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        flash('danger', 'That area is restricted to administrators.');
        redirect('dashboard.php');
    }
}

/**
 * Ownership guard: admins can manage everything, staff only their own records.
 * A record is "theirs" when they created it or it is assigned to them.
 */
function can_manage(array $record): bool
{
    if (is_admin()) {
        return true;
    }
    $userId = (int) current_user_id();
    return (int) ($record['created_by'] ?? 0) === $userId
        || (int) ($record['assigned_to'] ?? 0) === $userId;
}

/**
 * Read guard: may this person open this record's detail page?
 *
 * can_manage() only ever gated writes, which left every read open: a staff
 * member could read any client's email, phone, address and full interaction
 * history by walking ?id=1,2,3... even though they could not edit it.
 *
 * Deliberately a separate function from can_manage() rather than a call to it.
 * They apply the same rule today, but they answer different questions - "may I
 * change this" versus "may I see this" - and a deployment that wants staff to
 * read the whole CRM while only editing their own records should be able to
 * relax one without touching the other.
 *
 * Lists and reports stay org-wide on purpose: scoping those would break team
 * performance reporting, which is how a small business knows who is behind.
 */
function can_view(array $record): bool
{
    if (is_admin()) {
        return true;
    }
    $userId = (int) current_user_id();
    return (int) ($record['created_by'] ?? 0) === $userId
        || (int) ($record['assigned_to'] ?? 0) === $userId;
}

/**
 * Read guard for a record whose owner columns are named differently.
 *
 * Activities have no assignee - they belong to whoever logged them - so they
 * cannot go through can_view() with the record as-is.
 */
function can_view_activity(array $activity): bool
{
    return can_view(['created_by' => $activity['created_by'] ?? 0, 'assigned_to' => 0]);
}
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
 * Attempt a login. Returns an error string on failure, or null on success.
 */
function attempt_login(string $email, string $password): ?string
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Same generic message for unknown email and wrong password.
    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        return 'Those credentials do not match our records.';
    }
    if ((int) $user['is_active'] !== 1) {
        return 'This account has been deactivated. Please contact an administrator.';
    }

    // New session id on privilege change (session fixation protection).
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'    => (int) $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ];
    $_SESSION['_created_at'] = time();
    $_SESSION['_last_activity'] = time();

    return null;
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
 */
function require_login(): void
{
    if (!is_logged_in()) {
        flash('warning', 'Please sign in to continue.');
        redirect('login.php');
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
        redirect('index.php');
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
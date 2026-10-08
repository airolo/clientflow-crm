<?php
/**
 * Sign-in attempt model - brute-force throttling and the sign-in audit trail.
 */

declare(strict_types=1);

/** Failures allowed against one email address within the window. */
function login_attempt_email_limit(): int
{
    return 5;
}

/** Failures allowed from one IP address within the window. */
function login_attempt_ip_limit(): int
{
    return 20;
}

/** Length of the throttling window, in seconds. */
function login_attempt_window(): int
{
    return 900;
}

/** The visiting address, normalised to what fits a VARCHAR(45). */
function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return substr($ip, 0, 45);
}

/**
 * Record an attempt, successful or not.
 *
 * $tenantId is null when the workspace could not be resolved. Those rows are
 * kept so that a run of guesses at made-up workspace names is still visible,
 * and so the IP limit still has something to count.
 */
function login_attempt_record(?int $tenantId, string $email, bool $succeeded): void
{
    $stmt = db()->prepare(
        'INSERT INTO login_attempts (tenant_id, email, ip, succeeded, user_agent) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $tenantId,
        substr($email, 0, 150),
        client_ip(),
        $succeeded ? 1 : 0,
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}

/**
 * Failed attempts against one account in the current window.
 *
 * Scoped to the tenant, not just the email: two businesses may both have an
 * admin@company.com, and without this one workspace's repeated failures would
 * throttle the other one's account.
 */
function login_attempt_recent_failures_by_email(?int $tenantId, string $email): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE tenant_id <=> ? AND email = ? AND succeeded = 0
           AND attempted_at > (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->execute([$tenantId, substr($email, 0, 150), login_attempt_window()]);
    return (int) $stmt->fetchColumn();
}

/** Failed attempts from one IP in the current window. Deliberately not tenant-scoped. */
function login_attempt_recent_failures_by_ip(string $ip): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip = ? AND succeeded = 0 AND attempted_at > (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->execute([substr($ip, 0, 45), login_attempt_window()]);
    return (int) $stmt->fetchColumn();
}

/**
 * True when this account or IP has exhausted its allowance.
 *
 * Counted before the password is verified so that a run of guesses is refused
 * without doing any hash comparison.
 */
function login_attempt_locked_out(?int $tenantId, string $email, string $ip): bool
{
    return login_attempt_recent_failures_by_email($tenantId, $email) >= login_attempt_email_limit()
        || login_attempt_recent_failures_by_ip($ip) >= login_attempt_ip_limit();
}

/** Seconds until the account's oldest failure ages out of the window. */
function login_attempt_retry_seconds(?int $tenantId, string $email): int
{
    $stmt = db()->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, MIN(attempted_at), NOW()) AS age
         FROM login_attempts
         WHERE tenant_id <=> ? AND email = ? AND succeeded = 0
           AND attempted_at > (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->execute([$tenantId, substr($email, 0, 150), login_attempt_window()]);
    $age = $stmt->fetchColumn();
    if ($age === false) {
        return 0;
    }
    return max(1, login_attempt_window() - (int) $age);
}

/**
 * Clear the failure history for an account after a successful sign-in, so a
 * genuine user who mistyped a few times is not left throttled.
 */
function login_attempt_clear(?int $tenantId, string $email): void
{
    $stmt = db()->prepare('DELETE FROM login_attempts WHERE tenant_id <=> ? AND email = ? AND succeeded = 0');
    $stmt->execute([$tenantId, substr($email, 0, 150)]);
}

/** Delete attempt rows older than the retention period. Keeps the table small. */
function login_attempt_prune(): void
{
    db()->query(
        'DELETE FROM login_attempts
         WHERE attempted_at < (NOW() - INTERVAL ' . (int) login_attempt_window() * 4 . ' SECOND)'
    );
}

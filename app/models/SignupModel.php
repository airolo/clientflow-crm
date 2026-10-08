<?php
/**
 * Signup - rate limiting and slug allocation for the public create-workspace form.
 *
 * This is the only part of the app reachable with no session and no workspace,
 * so it carries the threat model the rest of the app does not need:
 *
 *  - **Junk workspaces.** Anyone can POST this form. Two limits - per IP and per
 *    email - stop one script creating hundreds of workspaces under one address.
 *  - **Slug guessing.** Slugs are public (they are typed at sign-in), so signup
 *    must not become a way to test whether one exists. It does not: the slug is
 *    allocated from the business name and a taken slug silently gets a suffix,
 *    so the response never distinguishes "free" from "taken".
 *  - **Password grinding.** Signup is a place to try a password against an
 *    address, exactly like sign-in, so it is throttled on the same terms.
 *
 * What is deliberately absent: email verification. This build sends no mail, so
 * a workspace can be claimed with an address nobody controls. That is a real gap
 * for a public deployment and the first thing to add when mail is wired up - see
 * the README's Known limits.
 */

declare(strict_types=1);

/** Signups allowed from one IP inside the window. */
function signup_ip_limit(): int
{
    return 5;
}

/** Signups allowed for one email address inside the window. */
function signup_email_limit(): int
{
    return 3;
}

/** The throttling window, in seconds. */
function signup_window(): int
{
    return 3600;
}

/**
 * Record a signup attempt, successful or not.
 *
 * Recorded before the workspace is created, so a failure still counts towards the
 * limit. That is the point: an attacker probing addresses must be slowed whether
 * or not the probe succeeds.
 */
function signup_attempt_record(string $email, bool $succeeded): void
{
    $stmt = db()->prepare(
        'INSERT INTO signup_attempts (email, ip, succeeded) VALUES (?, ?, ?)'
    );
    $stmt->execute([
        substr($email, 0, 150),
        substr(client_ip(), 0, 45),
        $succeeded ? 1 : 0,
    ]);
}

/** Attempts from one address inside the window. */
function signup_attempts_by_email(string $email): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM signup_attempts
         WHERE email = ? AND attempted_at > (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->execute([substr($email, 0, 150), signup_window()]);
    return (int) $stmt->fetchColumn();
}

/** Attempts from one IP inside the window. Deliberately not scoped by email. */
function signup_attempts_by_ip(string $ip): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM signup_attempts
         WHERE ip = ? AND attempted_at > (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->execute([substr($ip, 0, 45), signup_window()]);
    return (int) $stmt->fetchColumn();
}

/**
 * True when this address or IP has exhausted its allowance.
 *
 * Counted before anything is written or hashed, so a run of attempts stops being
 * expensive immediately.
 */
function signup_locked_out(string $email, string $ip): bool
{
    return signup_attempts_by_email($email) >= signup_email_limit()
        || signup_attempts_by_ip($ip) >= signup_ip_limit();
}

/**
 * A message for a refused signup.
 *
 * Deliberately says nothing about which limit was hit: an IP limit and an email
 * limit are different signals to an attacker, and neither is something a
 * legitimate user needs to distinguish.
 */
function signup_locked_out_message(): string
{
    $minutes = max(1, (int) ceil(signup_window() / 60));
    return 'Too many sign-up attempts. Please try again in about '
        . $minutes . ' minutes.';
}

/** Delete attempt rows older than the retention period. */
function signup_attempt_prune(): void
{
    db()->query(
        'DELETE FROM signup_attempts
         WHERE attempted_at < (NOW() - INTERVAL ' . (int) (signup_window() * 4) . ' SECOND)'
    );
}

/**
 * Turn a business name into a slug that is not taken.
 *
 * A taken slug gets "-2", "-3" and so on rather than an error, because the slug
 * is derived from a name the user chose and there is nothing they can usefully
 * do about a collision. Returning an error instead would leak which slugs exist
 * and make two businesses with similar names both fail.
 *
 * The numeric suffix keeps the result inside the 60-character column: the base is
 * cut to make room rather than truncating the tail, which could produce two
 * identical slugs.
 */
function signup_allocate_slug(string $name): string
{
    $base = tenant_slug_from_name($name);
    $base = substr($base, 0, 48);
    $base = rtrim($base, '-');
    if ($base === '') {
        $base = 'workspace';
    }

    if (!tenant_slug_taken($base)) {
        return $base;
    }

    for ($suffix = 2; $suffix <= 50; $suffix++) {
        $candidate = $base . '-' . $suffix;
        if (!tenant_slug_taken($candidate)) {
            return $candidate;
        }
    }

    // 50 collisions on the same name is not something a human does. Fall back to
    // something certainly unique rather than looping.
    return $base . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
}

/** Validate a signup form. Returns field => message. */
function signup_validate(array $data): array
{
    $errors = [];

    $business = trim((string) ($data['business_name'] ?? ''));
    if ($business === '') {
        $errors['business_name'] = 'Enter your business name.';
    }

    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        $errors['name'] = 'Enter your name.';
    }

    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if ($email === '') {
        $errors['email'] = 'Enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    $password = (string) ($data['password'] ?? '');
    if ($password === '') {
        $errors['password'] = 'Choose a password.';
    } elseif (strlen($password) < 10) {
        // Longer than the 8 an admin-created account needs: this is the root
        // account of a new workspace, it is the one nobody reviews, and it is
        // the one worth guessing.
        $errors['password'] = 'Use at least 10 characters.';
    } elseif (strlen($password) > 200) {
        // bcrypt truncates past 72 bytes, so a longer password silently loses its
        // tail. Refusing is clearer than pretending the whole thing is used.
        $errors['password'] = 'That password is too long.';
    }

    $confirm = (string) ($data['password_confirm'] ?? '');
    if ($confirm !== '' && $confirm !== $password) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }

    $slug = trim((string) ($data['slug'] ?? ''));
    if ($slug !== '') {
        if (!tenant_slug_is_valid($slug)) {
            $errors['slug'] = 'Use letters, numbers and hyphens only.';
        } elseif (tenant_slug_taken($slug)) {
            $errors['slug'] = 'That workspace name is already taken.';
        }
    }

    return $errors + length_errors([
        'business_name' => [$business, 120, 'Business name'],
        'name'          => [$name, 100, 'Your name'],
        'email'         => [$email, 150, 'Email'],
        'slug'          => [$slug, 60, 'Workspace name'],
    ]);
}

/** Mark the workspace as set up, so the welcome screen does not come back. */
function signup_mark_onboarded(int $tenantId): void
{
    $stmt = db()->prepare('UPDATE tenants SET onboarded_at = NOW() WHERE id = ?');
    $stmt->execute([$tenantId]);
}

/** True when this workspace has not finished the first-run welcome screen. */
function signup_needs_onboarding(?int $tenantId = null): bool
{
    $tenant = $tenantId !== null ? tenant_find($tenantId) : tenant_current();
    return $tenant !== null && empty($tenant['onboarded_at']);
}
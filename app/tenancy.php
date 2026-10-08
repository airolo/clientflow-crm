<?php
/**
 * Tenant context - which business the current request belongs to.
 *
 * Every record table carries a tenant_id, and every query filters on it. This
 * file owns the single question of where that value comes from, so no page has
 * to decide for itself.
 *
 * The rule: the tenant id is read from the session and nowhere else. It is
 * never taken from a URL, a form field or a hidden input. A page that accepted
 * ?tenant_id= would be one forgotten parameter away from showing a stranger's
 * customers, which is the whole risk this design exists to remove.
 */

declare(strict_types=1);

/** Per-request cache, so repeated calls do not re-query on every page. */
$GLOBALS['__cf_tenant_cache'] = null;

/**
 * The tenant row for the current session, or null when not signed in.
 *
 * Re-read from the database rather than trusted from the session alone: a
 * workspace suspended mid-session must stop working now, not in two hours when
 * the idle timeout fires. The id comes from the session; the status comes from
 * the database.
 */
function tenant_current(): ?array
{
    if ($GLOBALS['__cf_tenant_cache'] !== null) {
        return $GLOBALS['__cf_tenant_cache'] ?: null;
    }

    $tenantId = $_SESSION['tenant_id'] ?? null;
    if ($tenantId === null) {
        $GLOBALS['__cf_tenant_cache'] = false;
        return null;
    }

    $GLOBALS['__cf_tenant_cache'] = tenant_find((int) $tenantId) ?: false;
    return $GLOBALS['__cf_tenant_cache'] ?: null;
}

/**
 * The current tenant's id, for building queries.
 *
 * Throws rather than returning 0 when there is no tenant. A silent 0 would
 * produce `WHERE tenant_id = 0`, which matches nothing - the page would look
 * empty rather than broken, and that empty page is exactly the bug that gets
 * missed until a customer reports missing data.
 */
function tenant_id(): int
{
    $id = (int) ($_SESSION['tenant_id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('No tenant in session. Require login before querying records.');
    }
    return $id;
}

/** The current tenant's slug, for links and the UI. */
function tenant_slug(): ?string
{
    return tenant_current()['slug'] ?? null;
}

/** The current tenant's id, or 0 when signed out. For read-only display code. */
function tenant_id_or_zero(): int
{
    return (int) ($_SESSION['tenant_id'] ?? 0);
}

/**
 * Guard: the session must belong to a workspace that still exists and is not
 * suspended.
 *
 * Called from require_login() so every authenticated page inherits it. A
 * suspended tenant is signed out rather than merely blocked, so a stale tab
 * cannot keep retrying.
 */
function require_active_tenant(): void
{
    if (!is_logged_in()) {
        return; // require_login() owns the "not signed in" redirect.
    }

    $tenant = tenant_current();
    if ($tenant === null) {
        // Signed in, but the workspace is gone. Do not leave the user in a
        // half-authenticated state.
        logout_user();
        flash('danger', 'That workspace is no longer available. Please sign in again.');
        redirect('login.php');
    }

    if ($tenant['status'] !== 'active') {
        logout_user();
        flash('danger', 'This workspace has been suspended. Please contact support.');
        redirect('login.php');
    }

    // After the status check, so a suspended workspace cannot change the app's
    // behaviour on the way out.
    tenant_apply_timezone();
}

/** True when this record belongs to the signed-in tenant. */
function tenant_owns(array $record): bool
{
    return (int) ($record['tenant_id'] ?? 0) === tenant_id();
}

/**
 * Currency for the current workspace, for formatting money.
 *
 * Falls back to GBP when there is no workspace - the public landing page and the
 * sign-in page run before one exists, and they still print money figures.
 */
function tenant_currency(): string
{
    return (string) (tenant_current()['currency'] ?? 'GBP');
}

/** The symbol to print in front of an amount. */
function tenant_currency_symbol(): string
{
    return tenant_currency_symbol_for(tenant_currency());
}

/** Apply the workspace's timezone for the rest of this request.
 *
 * Called from require_active_tenant(), which every authenticated page reaches
 * before any model runs. Without it the whole app would print times in
 * APP_TIMEZONE and a workspace in Auckland would see its own deadlines an hour
 * out.
 *
 * Guarded rather than assumed: a stored zone that PHP no longer recognises (a
 * tzdata rename, or a hand-edited row) must not take the site down, so an
 * invalid value is ignored and APP_TIMEZONE stands.
 */
function tenant_apply_timezone(): void
{
    $tz = (string) (tenant_current()['timezone'] ?? '');
    if ($tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        date_default_timezone_set($tz);
    }
}

/** Drop the per-request cache. Used by tests and after a tenant switch. */
function tenant_cache_reset(): void
{
    $GLOBALS['__cf_tenant_cache'] = null;
}
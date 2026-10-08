<?php
/**
 * First-run welcome screen.
 *
 * Shown once to an owner who has just created a workspace. The point is to make
 * the three things worth doing obvious on an otherwise empty CRM: add a client,
 * import a file if you have one, and set the currency.
 *
 * The "seen" flag lives in tenants.onboarded_at, not in the session, so it does
 * not reappear on a different device and it does not show up for anyone else.
 * This page is also linked from the dashboard for anyone who dismisses it.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_login();

$tenant = tenant_current();
if ($tenant === null) {
    flash_error('That workspace is no longer available.', 'dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    signup_mark_onboarded((int) $tenant['id']);
    tenant_cache_reset();
    flash_success('You are all set.', 'dashboard.php');
}

$pageTitle    = 'Welcome';
$pageHeading  = 'Welcome, ' . current_user()['name'];
$pageSubtitle = 'Three things worth doing to get started';
$activeNav    = 'dashboard';
$breadcrumbs  = ['Dashboard' => 'dashboard.php', 'Welcome' => null];

require __DIR__ . '/views/header.php';
?>

<div class="row g-4">
    <div class="col-12 col-lg-8">

        <div class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <h2 class="h5 fw-bold mb-1">1. Add your first client</h2>
                <p class="text-secondary mb-3">
                    A client is an account you already trade with. Leads are the prospects you
                    have not converted yet, and you can keep the two apart or move between them.
                </p>
                <a href="<?= e(url('clients/form.php')) ?>" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>Add a client
                </a>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <h2 class="h5 fw-bold mb-1">2. Already have a spreadsheet?</h2>
                <p class="text-secondary mb-3">
                    Import a CSV and you will see exactly what would be added - including which
                    rows look like things you already have - before anything is saved. Columns are
                    matched loosely, so <code>Company Name</code> and <code>company_name</code> both
                    work. Files exported from this app come straight back in.
                </p>
                <a href="<?= e(url('import.php?type=client')) ?>" class="btn btn-outline-secondary me-2">
                    <i class="bi bi-upload me-1"></i>Import clients
                </a>
                <a href="<?= e(url('import.php?type=lead')) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-upload me-1"></i>Import leads
                </a>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <h2 class="h5 fw-bold mb-1">3. Set your currency</h2>
                <p class="text-secondary mb-3">
                    Every figure in the app is shown in your workspace's currency. It starts as
                    <strong><?= e(tenant_currency()) ?></strong>; change it and the whole app
                    follows. Amounts are stored as plain numbers, so switching currency never
                    converts anything.
                </p>
                <a href="<?= e(url('admin/settings.php')) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-sliders me-1"></i>Workspace settings
                </a>
            </div>
        </div>

        <form method="post" action="<?= e(url('welcome.php')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Take me to the dashboard
            </button>
        </form>

    </div>

    <div class="col-12 col-lg-4">

        <div class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3">Your workspace</h2>
                <dl class="row mb-0 small">
                    <dt class="col-5 text-secondary fw-normal">Name</dt>
                    <dd class="col-7"><?= e((string) $tenant['name']) ?></dd>

                    <dt class="col-5 text-secondary fw-normal">Sign-in name</dt>
                    <dd class="col-7"><code><?= e((string) $tenant['slug']) ?></code></dd>

                    <dt class="col-5 text-secondary fw-normal">Plan</dt>
                    <dd class="col-7 text-capitalize"><?= e((string) $tenant['plan']) ?></dd>

                    <dt class="col-5 text-secondary fw-normal">Currency</dt>
                    <dd class="col-7"><?= e((string) $tenant['currency']) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-2">Adding your team</h2>
                <p class="small text-secondary mb-2">
                    Staff accounts can be added from <em>Users</em> in the sidebar. Give each person
                    their own account rather than sharing yours: activity is recorded against whoever
                    is signed in, and permissions are per person.
                </p>
                <p class="small text-secondary mb-0">
                    Sharing one login means every client's details look like yours, and anything
                    that gets deleted looks like you deleted it.
                </p>
            </div>
        </div>

    </div>
</div>

<?php require __DIR__ . '/views/footer.php'; ?>
<?php
/**
 * Workspace settings (admin only).
 *
 * The per-workspace values that change how the app presents itself: the
 * business name, the currency money is printed in, and the timezone dates are
 * printed in.
 *
 * Only this workspace's own row is ever written. There is no tenant selector
 * here and no id in the form - the id comes from the session. That is the whole
 * point of the tenancy work, and a settings page that could edit any workspace
 * would undo it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$errors = take_errors();
$old    = take_old();
$tenant = tenant_current();

if ($tenant === null) {
    flash_error('That workspace is no longer available.', 'dashboard.php');
}

/** Validate the settings form. Returns field => message. */
function validate_settings_input(array $data): array
{
    $errors = [];

    $name = $data['name'] ?? '';
    if ($name === '') {
        $errors['name'] = 'Enter your business name.';
    }

    $currency = $data['currency'] ?? '';
    if (!tenant_currency_is_valid($currency)) {
        $errors['currency'] = 'Choose a currency from the list.';
    }

    $timezone = $data['timezone'] ?? '';
    if (!tenant_timezone_is_valid($timezone)) {
        $errors['timezone'] = 'Choose a timezone from the list.';
    }

    // Matches the VARCHAR widths in database.sql.
    return $errors + length_errors([
        'name'     => [$name, 120, 'Business name'],
        'currency' => [$currency, 8, 'Currency'],
        'timezone' => [$timezone, 64, 'Timezone'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $data = [
        'name'     => post_str('name'),
        'currency' => strtoupper(post_str('currency')),
        'timezone' => post_str('timezone'),
    ];

    $errors = validate_settings_input($data);

    if (!$errors) {
        tenant_update_settings((int) $tenant['id'], $data);
        // The currency symbol and timezone are cached for the request, so the
        // redirect has to happen for the new values to take effect.
        flash_success('Workspace settings saved.', 'admin/settings.php');
    } else {
        redirect_with_errors('admin/settings.php', $errors, $data);
    }
}

$value = static function (string $key, string $fallback = '') use ($old, $tenant): string {
    return old_value($old, (array) $tenant, $key, $fallback);
};

// What time it is for this workspace right now.
//
// A timezone setting that changes nothing you can see is impossible to confirm,
// which is its own kind of bug. require_active_tenant() has already applied the
// workspace zone for this request, so date() here reflects the saved setting.
// The UTC offset is shown as well as the clock, because the clock alone reads
// the same in two zones that happen to be hours apart at certain times of day.
$now        = new DateTimeImmutable('now');
$offsetSec  = $now->getOffset();
$offsetAbs  = abs($offsetSec);
$offsetText = sprintf(
    'UTC%s%02d:%02d',
    $offsetSec < 0 ? '-' : '+',
    intdiv($offsetAbs, 3600),
    intdiv($offsetAbs % 3600, 60)
);
$clockNow = $now->format('H:i');

$pageTitle    = 'Workspace settings';
$pageHeading  = 'Workspace settings';
$pageSubtitle = 'How this workspace is named and how money and dates are shown';
$activeNav    = 'settings';
$breadcrumbs  = ['Dashboard' => 'dashboard.php', 'Settings' => null];

require __DIR__ . '/../views/header.php';
?>

<div class="row g-4">
    <div class="col-12 col-lg-7">

        <div class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <form method="post" action="<?= e(url('admin/settings.php')) ?>" data-validate novalidate>
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="name" class="form-label">Business name</label>
                        <input type="text" name="name" id="name"
                               class="form-control<?= is_invalid($errors, 'name') ?>"
                               value="<?= e($value('name')) ?>"
                               maxlength="120" required autofocus>
                        <div class="form-text">
                            Shown in the sidebar and on exported files.
                        </div>
                        <?php if ($message = field_error($errors, 'name')): ?>
                            <div class="invalid-feedback d-block"><?= e($message) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="currency" class="form-label">Currency</label>
                        <select name="currency" id="currency"
                                class="form-select<?= is_invalid($errors, 'currency') ?>">
                            <?php foreach (tenant_currencies() as $code => $currency): ?>
                                <option value="<?= e($code) ?>"
                                    <?= $value('currency', 'GBP') === $code ? 'selected' : '' ?>>
                                    <?= e($currency['symbol'] . ' — ' . $currency['name'] . ' (' . $code . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Amounts are stored as plain numbers and only formatted for display, so
                            changing this does not convert or rewrite anything.
                        </div>
                        <?php if ($message = field_error($errors, 'currency')): ?>
                            <div class="invalid-feedback d-block"><?= e($message) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-0">
                        <label for="timezone" class="form-label">Timezone</label>
                        <select name="timezone" id="timezone"
                                class="form-select<?= is_invalid($errors, 'timezone') ?>">
                            <?php foreach (tenant_timezones() as $tz => $label): ?>
                                <option value="<?= e($tz) ?>"
                                    <?= $value('timezone', 'Europe/London') === $tz ? 'selected' : '' ?>>
                                    <?= e($label . ' (' . $tz . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Used for every date and time the app prints, and for anything due
                            "today".
                        </div>
                        <div class="form-text mt-1">
                            <i class="bi bi-clock me-1"></i>Right now it is
                            <strong><?= e($clockNow) ?> <?= e($now->format('T')) ?></strong>
                            (<?= e($offsetText) ?>)
                        </div>
                        <?php if ($message = field_error($errors, 'timezone')): ?>
                            <div class="invalid-feedback d-block"><?= e($message) ?></div>
                        <?php endif; ?>
                    </div>

                    <hr class="my-4">

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save settings
                    </button>
                </form>
            </div>
        </div>

    </div>

    <div class="col-12 col-lg-5">

        <div class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3">This workspace</h2>
                <dl class="row mb-0 small">
                    <dt class="col-5 text-secondary fw-normal">Workspace URL</dt>
                    <dd class="col-7"><code><?= e((string) $tenant['slug']) ?></code></dd>

                    <dt class="col-5 text-secondary fw-normal">Plan</dt>
                    <dd class="col-7 text-capitalize"><?= e((string) $tenant['plan']) ?></dd>

                    <dt class="col-5 text-secondary fw-normal">Status</dt>
                    <dd class="col-7">
                        <?php if ($tenant['status'] === 'active'): ?>
                            <span class="badge text-bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge text-bg-danger">Suspended</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-5 text-secondary fw-normal">Staff accounts</dt>
                    <dd class="col-7"><?= (int) tenant_user_count() ?></dd>

                    <dt class="col-5 text-secondary fw-normal">Created</dt>
                    <dd class="col-7"><?= e(nice_date((string) $tenant['created_at'])) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-2">
                    <i class="bi bi-info-circle me-1"></i>The workspace URL is fixed
                </h2>
                <p class="small text-secondary mb-0">
                    People sign in with this workspace name, so changing it would lock them out
                    until you told them the new one. It cannot be changed here.
                </p>
            </div>
        </div>

    </div>
</div>

<?php require __DIR__ . '/../views/footer.php'; ?>
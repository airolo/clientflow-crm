<?php
/**
 * Forced password change - the page a seeded or reset account is held on
 * until its publicly known password has been replaced.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId  = (int) current_user_id();
$account = user_find($userId);

if (!$account) {
    logout_user();
    flash_error('Your account could not be loaded.', 'auth/login.php');
    redirect('auth/login.php');
}

$errors = take_errors();
$old    = take_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $errors = [];

    if (!password_verify($current, (string) $account['password_hash'])) {
        $errors['current_password'] = 'That is not your current password.';
    }
    if (mb_strlen($new) < 8) {
        $errors['new_password'] = 'Use at least 8 characters.';
    } elseif ($new === $current) {
        $errors['new_password'] = 'Choose a password different from the current one.';
    }
    if ($new !== $confirm) {
        $errors['confirm_password'] = 'The two passwords do not match.';
    }

    if (!$errors) {
        // Clears the flag in the database and in the session together, so the
        // next request is not sent straight back here.
        user_set_password($userId, $new, true);
        unset($_SESSION['must_change_password']);
        flash_success('Your password has been changed.', 'dashboard.php');
    }

    redirect_with_errors('auth/change_password.php', $errors, []);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change your password &middot; <?= e(APP_NAME) ?></title>
    <link href="<?= e(url('assets/vendor/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(url('assets/vendor/css/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body class="login-body">
<div class="login-card">
    <div class="login-hero">
        <span class="brand-mark mb-3" style="width:48px;height:48px;font-size:1.4rem">
            <i class="bi bi-shield-lock-fill"></i>
        </span>
        <h1 class="h4 fw-bold mb-1">Choose a new password</h1>
        <p class="text-secondary mb-0">Signed in as <?= e($account['email']) ?></p>
    </div>

    <div class="px-4 pb-4">
        <div class="alert alert-warning" role="alert">
            <i class="bi bi-exclamation-triangle me-1"></i>
            This account still uses its original password. Set a new one to continue.
        </div>

        <?php require __DIR__ . '/../views/alerts.php'; ?>

        <form method="post" action="<?= url('auth/change_password.php') ?>" data-validate novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="current_password" class="form-label required">Current password</label>
                <input type="password" name="current_password" id="current_password" required
                       class="form-control<?= is_invalid($errors, 'current_password') ?>"
                       autocomplete="current-password" autofocus>
                <?php if ($m = field_error($errors, 'current_password')): ?>
                    <div class="invalid-feedback d-block"><?= e($m) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="new_password" class="form-label required">New password</label>
                <input type="password" name="new_password" id="new_password" required minlength="8"
                       class="form-control<?= is_invalid($errors, 'new_password') ?>"
                       autocomplete="new-password">
                <?php if ($m = field_error($errors, 'new_password')): ?>
                    <div class="invalid-feedback d-block"><?= e($m) ?></div>
                <?php else: ?>
                    <div class="form-text">At least 8 characters, and different from the current one.</div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="confirm_password" class="form-label required">Confirm new password</label>
                <input type="password" name="confirm_password" id="confirm_password" required minlength="8"
                       class="form-control<?= is_invalid($errors, 'confirm_password') ?>"
                       autocomplete="new-password">
                <?php if ($m = field_error($errors, 'confirm_password')): ?>
                    <div class="invalid-feedback d-block"><?= e($m) ?></div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="bi bi-check-lg me-1"></i>Set new password
            </button>
        </form>

        <form method="post" action="<?= url('auth/logout.php') ?>" class="mt-3 text-center">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-link btn-sm text-secondary">Sign out instead</button>
        </form>
    </div>
</div>
</body>
</html>

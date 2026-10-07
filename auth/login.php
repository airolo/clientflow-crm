<?php
/**
 * ClientFlow CRM - sign in
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$errors = take_errors();
$old    = take_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email    = strtolower(post_str('email'));
    $password = (string) ($_POST['password'] ?? '');

    $errors = [];
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if ($password === '') {
        $errors['password'] = 'Enter your password.';
    }

    if (!$errors) {
        $loginError = attempt_login($email, $password);
        if ($loginError === null) {
            flash_success('Welcome back, ' . current_user()['name'] . '.', 'dashboard.php');
        }
        $errors['password'] = $loginError;
    }

    if ($errors) {
        redirect_with_errors('auth/login.php', $errors, ['email' => $email]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in &middot; <?= e(APP_NAME) ?></title>
    <link href="<?= e(url('assets/vendor/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(url('assets/vendor/css/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body class="login-body">
<div class="login-card">
    <div class="login-hero">
        <span class="brand-mark mb-3" style="width:48px;height:48px;font-size:1.4rem">
            <i class="bi bi-diagram-3-fill"></i>
        </span>
        <h1 class="h4 fw-bold mb-1"><?= e(APP_NAME) ?></h1>
        <p class="text-secondary mb-0">Sign in to manage your customers</p>
    </div>

    <div class="px-4 pb-4">
        <?php require __DIR__ . '/../views/alerts.php'; ?>

        <form method="post" action="<?= url('auth/login.php') ?>" data-validate novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="email" class="form-label">Email address</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-envelope"></i></span>
                    <input type="email" name="email" id="email"
                           class="form-control<?= is_invalid($errors, 'email') ?>"
                           value="<?= e(old_value($old, [], 'email')) ?>"
                           placeholder="you@company.test" autocomplete="email" required autofocus>
                </div>
                <?php if ($message = field_error($errors, 'email')): ?>
                    <div class="invalid-feedback d-block"><?= e($message) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" id="password"
                           class="form-control<?= is_invalid($errors, 'password') ?>"
                           placeholder="Enter your password" autocomplete="current-password" required>
                    <button class="btn btn-outline-secondary" type="button" id="togglePassword"
                            aria-label="Show password" tabindex="-1">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <?php if ($message = field_error($errors, 'password')): ?>
                    <div class="invalid-feedback d-block"><?= e($message) ?></div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="bi bi-box-arrow-in-right me-1"></i>Sign in
            </button>
        </form>

<?php if (DEMO_MODE): ?>
        <div class="demo-cred mt-4">
            <div class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i>Demo accounts</div>
            <div class="mb-1">
                <strong>Admin:</strong> <code><?= e(DEMO_ADMIN_EMAIL) ?></code> / <code><?= e(DEMO_ADMIN_PASS) ?></code>
            </div>
            <div>
                <strong>Staff:</strong> <code><?= e(DEMO_STAFF_EMAIL) ?></code> / <code><?= e(DEMO_STAFF_PASS) ?></code>
            </div>
        </div>
<?php endif; ?>
    </div>
</div>

<script src="<?= e(url('assets/vendor/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
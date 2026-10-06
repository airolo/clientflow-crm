<?php
/**
 * My profile - view details and change password.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$userId  = (int) current_user_id();
$account = user_find($userId);

if (!$account) {
    logout_user();
    flash_error('Your account could not be loaded.', 'login.php');
    redirect('login.php');
}

$errors  = take_errors();
$old     = take_old();
$sidebarPendingTasks = task_count_open_for_sidebar($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = post_str('action');
    $errors = [];

    if ($action === 'details') {
        $data = [
            'name'  => post_str('name'),
            'email' => strtolower(post_str('email')),
            'phone' => post_str('phone'),
        ];

        if ($data['name'] === '') {
            $errors['name'] = 'Full name is required.';
        } elseif (mb_strlen($data['name']) > 100) {
            $errors['name'] = 'Keep the name under 100 characters.';
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (user_email_exists($data['email'], $userId)) {
            $errors['email'] = 'Another account already uses that email.';
        }

        if (!$errors) {
            user_update_profile($userId, $data);
            // Keep the session copy of the user in step with the database.
            $_SESSION['user']['name']  = $data['name'];
            $_SESSION['user']['email'] = $data['email'];
            flash_success('Your profile has been updated.', 'profile.php');
        }

        redirect_with_errors('profile.php', $errors, $_POST);
    }

    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($current, (string) $account['password_hash'])) {
            $errors['current_password'] = 'Your current password is not correct.';
        }
        if (mb_strlen($new) < 8) {
            $errors['new_password'] = 'Use at least 8 characters.';
        }
        if ($new !== $confirm) {
            $errors['confirm_password'] = 'The two passwords do not match.';
        }

        if (!$errors) {
            user_update_password($userId, $new);
            flash_success('Your password has been changed.', 'profile.php');
        }

        redirect_with_errors('profile.php', $errors, $_POST);
    }
}

$pageTitle    = 'My profile';
$pageHeading  = 'My profile';
$pageSubtitle = 'Update your details and password';
$activeNav    = '';
$breadcrumbs  = ['Dashboard' => 'index.php', 'Profile' => null];

require __DIR__ . '/includes/header.php';
?>

<div class="row g-3">
    <!-- Summary -->
    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-body text-center">
                <span class="avatar avatar-lg mb-2" style="background:<?= e(avatar_colour($account['name'])) ?>">
                    <?= e(initials($account['name'])) ?>
                </span>
                <h2 class="h6 mb-1"><?= e($account['name']) ?></h2>
                <div class="small text-secondary mb-2"><?= e($account['email']) ?></div>
                <span class="badge text-bg-<?= $account['role'] === 'admin' ? 'primary' : 'secondary' ?>">
                    <?= e(ucfirst($account['role'])) ?>
                </span>
            </div>
            <div class="card-body border-top">
                <dl class="detail-list mb-0">
                    <dt>Phone</dt>
                    <dd class="mb-2"><?= e($account['phone'] ?: 'Not provided') ?></dd>
                    <dt>Member since</dt>
                    <dd class="mb-2"><?= e(nice_date($account['created_at'])) ?></dd>
                    <dt>Clients assigned</dt>
                    <dd class="mb-0">
                        <?= (int) (db()->query('SELECT COUNT(*) FROM clients WHERE assigned_to = ' . (int) $userId)->fetchColumn()) ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <!-- Details -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-person me-2 text-primary"></i>Profile details</div>
            <form method="post" action="profile.php" data-validate novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="details">

                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="name" class="form-label required">Full name</label>
                            <input type="text" name="name" id="name" required maxlength="100"
                                   class="form-control<?= is_invalid($errors, 'name') ?>"
                                   value="<?= e(old_value($old, $account, 'name')) ?>">
                            <?php if ($m = field_error($errors, 'name')): ?>
                                <div class="invalid-feedback d-block"><?= e($m) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="email" class="form-label required">Email</label>
                            <input type="email" name="email" id="email" required maxlength="150"
                                   class="form-control<?= is_invalid($errors, 'email') ?>"
                                   value="<?= e(old_value($old, $account, 'email')) ?>">
                            <?php if ($m = field_error($errors, 'email')): ?>
                                <div class="invalid-feedback d-block"><?= e($m) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" name="phone" id="phone" maxlength="40"
                                   class="form-control" value="<?= e(old_value($old, $account, 'phone')) ?>">
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save details</button>
                </div>
            </form>
        </div>

        <!-- Password -->
        <div class="card">
            <div class="card-header"><i class="bi bi-shield-lock me-2 text-primary"></i>Change password</div>
            <form method="post" action="profile.php" data-validate novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">

                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label for="current_password" class="form-label required">Current password</label>
                            <input type="password" name="current_password" id="current_password" required
                                   class="form-control<?= is_invalid($errors, 'current_password') ?>"
                                   autocomplete="current-password">
                            <?php if ($m = field_error($errors, 'current_password')): ?>
                                <div class="invalid-feedback d-block"><?= e($m) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="new_password" class="form-label required">New password</label>
                            <input type="password" name="new_password" id="new_password" required minlength="8"
                                   class="form-control<?= is_invalid($errors, 'new_password') ?>"
                                   autocomplete="new-password">
                            <?php if ($m = field_error($errors, 'new_password')): ?>
                                <div class="invalid-feedback d-block"><?= e($m) ?></div>
                            <?php else: ?>
                                <div class="form-text">At least 8 characters.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="confirm_password" class="form-label required">Confirm password</label>
                            <input type="password" name="confirm_password" id="confirm_password" required minlength="8"
                                   class="form-control<?= is_invalid($errors, 'confirm_password') ?>"
                                   autocomplete="new-password">
                            <?php if ($m = field_error($errors, 'confirm_password')): ?>
                                <div class="invalid-feedback d-block"><?= e($m) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i>Update password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
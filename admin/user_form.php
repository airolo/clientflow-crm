<?php
/**
 * User create / edit form (admin only).
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$id     = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$target = $id > 0 ? user_find($id) : null;

if ($id > 0 && !$target) {
    flash_error('That user no longer exists.', 'admin/users.php');
}

$errors  = take_errors();
$old     = take_old();
$isEdit  = $target !== null;

/** Shared validation for the create and edit user forms. */
function validate_user_input(array $data, ?int $exceptId = null): array
{
    $errors = [];

    if (($data['name'] ?? '') === '') {
        $errors['name'] = 'Full name is required.';
    }

    $email = $data['email'] ?? '';
    if ($email === '') {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (user_email_exists($email, $exceptId)) {
        $errors['email'] = 'Another account already uses that email.';
    }

    if (!in_array($data['role'] ?? '', ['admin', 'staff'], true)) {
        $errors['role'] = 'Choose either the admin or staff role.';
    }

    // Password is required on create, optional on edit.
    if (!$exceptId && ($data['password'] ?? '') === '') {
        $errors['password'] = 'A password is required.';
    }
    if (($data['password'] ?? '') !== '' && mb_strlen($data['password']) < 8) {
        $errors['password'] = 'Use at least 8 characters.';
    }

    // Length limits mirror the VARCHAR widths in database.sql.
    return $errors + length_errors([
        'name'  => [$data['name'] ?? '', 100, 'Full name'],
        'email' => [$email, 150, 'Email'],
        'phone' => [$data['phone'] ?? '', 40, 'Phone'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $data = [
        'name'     => post_str('name'),
        'email'    => strtolower(post_str('email')),
        'password' => (string) ($_POST['password'] ?? ''),
        'role'     => post_str('role', 'staff'),
        'phone'    => post_str('phone'),
        // The form posts a hidden "0" plus a checkbox "1", so compare the value.
        'is_active' => post_str('is_active', '0') === '1' ? 1 : 0,
        'must_change_password' => post_str('must_change_password', '0') === '1' ? 1 : 0,
    ];

    $errors = validate_user_input($data, $isEdit ? $id : null);

    // Never allow the last active admin to be demoted or disabled.
    if ($isEdit && $errors === [] && $target['role'] === 'admin'
        && ($data['role'] !== 'admin' || !$data['is_active'])) {
        if (user_admin_count() <= 1) {
            $errors['role'] = 'This is the only active admin. Promote another user first.';
        }
    }
    if ($isEdit && (int) $target['id'] === (int) current_user_id() && !$data['is_active']) {
        $errors['is_active'] = 'You cannot disable your own account.';
    }

    if (!$errors) {
        if ($isEdit) {
            user_update_admin($id, $data);
            flash_success('User "' . $data['name'] . '" was updated.', 'admin/users.php');
        } else {
            user_create($data);
            flash_success('User "' . $data['name'] . '" was created.', 'admin/users.php');
        }
    }

    redirect_with_errors(
        $isEdit ? 'admin/user_form.php?id=' . $id : 'admin/users.php',
        $errors,
        $_POST
    );
}

$record = $target ?? ['role' => 'staff', 'is_active' => 1];

$pageTitle    = $isEdit ? 'Edit user' : 'New user';
$pageHeading  = $isEdit ? 'Edit user' : 'New user';
$pageSubtitle = $isEdit ? $target['name'] : 'Create a login account';
$activeNav    = 'users';
$breadcrumbs  = ['Dashboard' => 'dashboard.php', 'Users' => 'admin/users.php', $isEdit ? 'Edit' : null];
$pageActions = [[
    'label' => 'Back to users',
    'href' => 'admin/users.php',
    'variant' => 'light border',
    'icon' => 'bi-arrow-left',
]];

require __DIR__ . '/../views/header.php';
?>

<form method="post" action="<?= url('admin/user_form.php') ?>" class="row justify-content-center" data-validate novalidate>
    <?= csrf_field() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">
    <?php endif; ?>

    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-person-gear me-2 text-primary"></i>Account details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label for="name" class="form-label required">Full name</label>
                        <input type="text" name="name" id="name" required maxlength="100"
                               class="form-control<?= is_invalid($errors, 'name') ?>"
                               value="<?= e(old_value($old, $record, 'name')) ?>">
                        <?php if ($m = field_error($errors, 'name')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="email" class="form-label required">Email</label>
                        <input type="email" name="email" id="email" required maxlength="150"
                               class="form-control<?= is_invalid($errors, 'email') ?>"
                               value="<?= e(old_value($old, $record, 'email')) ?>">
                        <?php if ($m = field_error($errors, 'email')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="phone" class="form-label">Phone</label>
                        <input type="text" name="phone" id="phone" maxlength="40"
                               class="form-control" value="<?= e(old_value($old, $record, 'phone')) ?>">
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="password" class="form-label <?= $isEdit ? '' : 'required' ?>">
                            Password <?= $isEdit ? '(leave blank to keep current)' : '' ?>
                        </label>
                        <input type="password" name="password" id="password" <?= $isEdit ? '' : 'required' ?>
                               minlength="8" autocomplete="new-password"
                               class="form-control<?= is_invalid($errors, 'password') ?>">
                        <?php if ($m = field_error($errors, 'password')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php else: ?>
                            <div class="form-text">Minimum 8 characters. Stored as a one-way hash.</div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="role" class="form-label required">Role</label>
                        <select name="role" id="role" class="form-select<?= is_invalid($errors, 'role') ?>" required>
                            <option value="staff" <?= old_value($old, $record, 'role', 'staff') === 'staff' ? 'selected' : '' ?>>Staff</option>
                            <option value="admin" <?= old_value($old, $record, 'role') === 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                        <?php if ($m = field_error($errors, 'role')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php else: ?>
                            <div class="form-text">Admins manage users and can edit any record.</div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input<?= is_invalid($errors, 'is_active') ?>" type="checkbox"
                                   name="is_active" id="is_active" value="1"
                                   <?= (string) old_value($old, $record, 'is_active', '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">
                                Account is active and can sign in
                            </label>
                            <?php if ($m = field_error($errors, 'is_active')): ?>
                                <div class="invalid-feedback d-block"><?= e($m) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input type="hidden" name="must_change_password" value="0">
                            <input class="form-check-input" type="checkbox"
                                   name="must_change_password" id="must_change_password" value="1"
                                   <?= (string) old_value($old, $record, 'must_change_password', '0') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="must_change_password">
                                Must set a new password at next sign-in
                            </label>
                            <div class="form-text">
                                Checked automatically when you set a password. The account is held on the
                                change-password screen until it is done.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex gap-2 justify-content-end">
                <a href="<?= url('admin/users.php') ?>" class="btn btn-light border">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Save changes' : 'Create user' ?>
                </button>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../views/footer.php'; ?>

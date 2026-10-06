<?php
/**
 * User POST actions: create (from the list modal) and delete.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('users.php');
}

verify_csrf();

$action = post_str('action');
$id     = (int) ($_POST['id'] ?? 0);

if ($action === 'create') {
    $data = [
        'name'      => post_str('name'),
        'email'     => strtolower(post_str('email')),
        'password'  => (string) ($_POST['password'] ?? ''),
        'role'      => post_str('role', 'staff'),
        'phone'     => post_str('phone'),
        'is_active' => 1,
    ];

    $errors = [];
    if ($data['name'] === '') {
        $errors[] = 'Full name is required.';
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    } elseif (user_email_exists($data['email'])) {
        $errors[] = 'Another account already uses that email.';
    }
    if (mb_strlen($data['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (!in_array($data['role'], ['admin', 'staff'], true)) {
        $errors[] = 'Choose either the admin or staff role.';
    }

    // Length limits mirror the VARCHAR widths in database.sql.
    foreach (length_errors([
        'name'  => [$data['name'], 100, 'Full name'],
        'email' => [$data['email'], 150, 'Email'],
        'phone' => [$data['phone'], 40, 'Phone'],
    ]) as $message) {
        $errors[] = $message;
    }

    if ($errors) {
        redirect_with_errors('users.php', $errors, $_POST);
    }

    user_create($data);
    flash_success('User "' . $data['name'] . '" was created.', 'users.php');
}

if ($action === 'delete') {
    $target = user_find($id);
    if (!$target) {
        flash_error('That user no longer exists.', 'users.php');
    }
    if ((int) $target['id'] === (int) current_user_id()) {
        flash_error('You cannot delete your own account.', 'users.php');
    }
    if ($target['role'] === 'admin' && user_admin_count() <= 1) {
        flash_error('This is the only active admin account and cannot be deleted.', 'users.php');
    }

    user_delete($id);
    flash_success('User "' . $target['name'] . '" was deleted.', 'users.php');
}

flash_error('Unknown action requested.', 'users.php');
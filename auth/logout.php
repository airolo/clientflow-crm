<?php
/**
 * Sign out. POST-only so a stray link or prefetch cannot log someone out.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

logout_user();

start_secure_session();
flash('success', 'You have been signed out.');
redirect('auth/login.php');
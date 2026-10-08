<?php
/**
 * Create a workspace (public - no session required).
 *
 * The first page in the app reachable without signing in. It creates a workspace
 * and its first admin together, signs that admin straight in, and sends them to
 * the dashboard. Nothing else in the app is public.
 *
 * The security thinking lives in app/models/SignupModel.php. What is worth
 * knowing here:
 *
 *  - Slugs are allocated rather than chosen, when the user leaves the field blank.
 *    A taken slug gets a suffix instead of an error, because the form must not
 *    become a way to ask which slugs exist.
 *  - The workspace and its owner are created in one transaction. A workspace with
 *    no admin cannot be signed into, and an admin with no workspace sees nothing.
 *  - On success the user is signed in via sign_in_user(), the same path sign-in
 *    uses, so the session shape is defined once.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Already signed in? There is no reason to be here.
if (is_logged_in()) {
    redirect('dashboard.php');
}

$errors = take_errors();
$old    = take_old();

$business = (string) ($old['business_name'] ?? post_str('business_name'));
$name     = (string) ($old['name'] ?? post_str('name'));
$email    = (string) ($old['email'] ?? post_str('email'));

// The slug is offered pre-filled from the business name, but the field is only
// validated when the user actually changed it.
$slug = (string) ($old['slug'] ?? '');
if ($slug === '' && $business !== '' && !isset($_POST['slug'])) {
    $slug = tenant_slug_from_name($business);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = strtolower(post_str('email'));

    // Throttled before validation and before any hashing, so a run of attempts
    // stops being expensive immediately rather than after the work is done.
    if (signup_locked_out($email, client_ip())) {
        signup_attempt_record($email, false);
        $errors['email'] = signup_locked_out_message();
    } else {
        $data = [
            'business_name'     => post_str('business_name'),
            'name'              => post_str('name'),
            'email'             => $email,
            'password'          => (string) ($_POST['password'] ?? ''),
            'password_confirm'  => (string) ($_POST['password_confirm'] ?? ''),
            'slug'              => post_str('slug'),
        ];

        $errors = signup_validate($data);

        if (!$errors) {
            // An explicit slug is respected because signup_validate() has
            // already confirmed it is free. A blank one is allocated, which is
            // the common case and the path that cannot leak a taken slug.
            $finalSlug = $data['slug'] !== '' ? $data['slug'] : signup_allocate_slug($data['business_name']);

            try {
                $created = tenant_create_with_owner(
                    [
                        'name'     => $data['business_name'],
                        'slug'     => $finalSlug,
                        'plan'     => 'trial',
                        'status'   => 'active',
                        // A new workspace starts on GBP/Europe/London because
                        // the whole app formats in one currency until someone
                        // changes it in Settings. Recording the choice rather
                        // than leaving it NULL means the settings page has
                        // something truthful to show.
                        'currency' => 'GBP',
                        'timezone' => 'Europe/London',
                    ],
                    [
                        'name'     => $data['name'],
                        'email'    => $data['email'],
                        'password' => $data['password'],
                    ]
                );
            } catch (Throwable $e) {
                error_log('ClientFlow signup failed: ' . $e->getMessage());
                $errors['email'] = 'We could not create that workspace. Please try again.';
            }

            if (!$errors) {
                signup_attempt_record($data['email'], true);
                signup_attempt_prune();

                $owner = user_find_in_tenant((int) $created['user_id'], (int) $created['tenant_id']);
                if ($owner !== null) {
                    sign_in_user($owner, (int) $created['tenant_id']);
                    flash_success(
                        'Your workspace is ready. You are signed in as its administrator.',
                        'welcome.php'
                    );
                }
                redirect('welcome.php');
            } else {
                // Recorded as a failure: the attempt happened and cost work.
                signup_attempt_record($data['email'], false);
            }
        } else {
            signup_attempt_record($email, false);
        }
    }

    // Never echo the password back. Only the fields worth keeping.
    redirect_with_errors('signup.php', $errors, [
        'business_name' => post_str('business_name'),
        'name'          => post_str('name'),
        'email'         => $email,
        'slug'          => post_str('slug'),
    ]);
}

$pageTitle = 'Create your workspace';

// Renders its own <head> rather than using views/header.php, for the same reason
// auth/login.php does: there is no session, no navbar and no sidebar yet.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create your workspace &middot; <?= e(APP_NAME) ?></title>
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
        <h1 class="h4 fw-bold mb-1">Create your workspace</h1>
        <p class="text-secondary mb-0">Free while you try it. No card needed.</p>
    </div>

    <div class="px-4 pb-4">
        <?php require __DIR__ . '/views/alerts.php'; ?>

        <form method="post" action="<?= url('signup.php') ?>" data-validate novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="business_name" class="form-label">Business name</label>
                <input type="text" name="business_name" id="business_name"
                       class="form-control<?= is_invalid($errors, 'business_name') ?>"
                       value="<?= e($business) ?>" maxlength="120" required autofocus>
                <?php if ($message = field_error($errors, 'business_name')): ?>
                    <div class="invalid-feedback d-block"><?= e($message) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="slug" class="form-label">Workspace name</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-buildings"></i></span>
                    <input type="text" name="slug" id="slug"
                           class="form-control<?= is_invalid($errors, 'slug') ?>"
                           value="<?= e($slug) ?>" maxlength="60" placeholder="leave blank and we will choose">
                </div>
                <div class="form-text">
                    This is how your team signs in, so it has to be something you can type on a
                    phone. Leave it blank and we will pick one from your business name.
                </div>
                <?php if ($message = field_error($errors, 'slug')): ?>
                    <div class="invalid-feedback d-block"><?= e($message) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="name" class="form-label">Your name</label>
                <input type="text" name="name" id="name"
                       class="form-control<?= is_invalid($errors, 'name') ?>"
                       value="<?= e($name) ?>" maxlength="100" autocomplete="name" required>
                <?php if ($message = field_error($errors, 'name')): ?>
                    <div class="invalid-feedback d-block"><?= e($message) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">Email address</label>
                <input type="email" name="email" id="email"
                       class="form-control<?= is_invalid($errors, 'email') ?>"
                       value="<?= e($email) ?>" maxlength="150" autocomplete="email" required>
                <?php if ($message = field_error($errors, 'email')): ?>
                    <div class="invalid-feedback d-block"><?= e($message) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" name="password" id="password"
                       class="form-control<?= is_invalid($errors, 'password') ?>"
                       autocomplete="new-password" required>
                <div class="form-text">At least 10 characters.</div>
                <?php if ($message = field_error($errors, 'password')): ?>
                    <div class="invalid-feedback d-block"><?= e($message) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="password_confirm" class="form-label">Confirm password</label>
                <input type="password" name="password_confirm" id="password_confirm"
                       class="form-control<?= is_invalid($errors, 'password_confirm') ?>"
                       autocomplete="new-password" required>
                <?php if ($message = field_error($errors, 'password_confirm')): ?>
                    <div class="invalid-feedback d-block"><?= e($message) ?></div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="bi bi-building-add me-1"></i>Create workspace
            </button>
        </form>

        <p class="text-center text-secondary small mt-3 mb-0">
            Already have one? <a href="<?= e(url('auth/login.php')) ?>">Sign in</a>
        </p>
    </div>
</div>

<script src="<?= e(url('assets/vendor/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
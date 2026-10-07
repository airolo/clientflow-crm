<?php
/**
 * Reusable top navigation bar.
 */
$currentUser = current_user();
?>
<nav class="navbar navbar-expand-lg app-navbar sticky-top">
    <div class="container-fluid">
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="offcanvas"
                data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-label="Toggle navigation">
            <i class="bi bi-list fs-3"></i>
        </button>

        <a class="navbar-brand d-flex align-items-center gap-2 me-auto me-lg-0" href="<?= url('dashboard.php') ?>">
            <span class="brand-mark"><i class="bi bi-diagram-3-fill"></i></span>
            <span class="fw-semibold"><?= e(APP_SHORT) ?></span>
        </a>

        <div class="d-flex align-items-center gap-2">
            <span class="d-none d-md-inline text-secondary small">
                <?= e(date('D, d M Y')) ?>
            </span>

            <div class="dropdown">
                <button class="btn btn-light d-flex align-items-center gap-2 border rounded-pill px-2 py-1"
                        type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="avatar avatar-sm" style="background:<?= e(avatar_colour($currentUser['name'])) ?>">
                        <?= e(initials($currentUser['name'])) ?>
                    </span>
                    <span class="d-none d-sm-inline small fw-semibold"><?= e($currentUser['name']) ?></span>
                    <i class="bi bi-chevron-down small"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li class="px-3 py-2">
                        <div class="fw-semibold"><?= e($currentUser['name']) ?></div>
                        <div class="small text-secondary"><?= e($currentUser['email']) ?></div>
                        <span class="badge text-bg-<?= is_admin() ? 'primary' : 'secondary' ?> mt-1">
                            <?= e(ucfirst($currentUser['role'])) ?>
                        </span>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= url('auth/profile.php') ?>"><i class="bi bi-person-gear me-2"></i>My profile</a></li>
                    <li>
                        <form method="post" action="<?= url('auth/logout.php') ?>" class="m-0">
                            <?= csrf_field() ?>
                            <button type="submit" class="dropdown-item text-danger">
                                <i class="bi bi-box-arrow-right me-2"></i>Sign out
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>
<?php
/**
 * Page head + top navbar + sidebar. Ends just after <main> is opened.
 */

$currentUser = current_user();
// Flash messages are consumed by alerts.php further down - do not read them here,
// or the queue is emptied before it can render.

// The sidebar task badge used to be fetched by all 17 pages individually.
// One query here serves every page instead.
$pendingTasks = $sidebarPendingTasks ?? task_count_open_for_sidebar((int) $currentUser['id']);

// Same idea for the recycle-bin badge. Staff never see it, so no query runs
// for them, and it is one UNION ALL rather than a COUNT per table.
$binCount = $sidebarBinCount ?? (is_admin() ? soft_delete_count() : 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> &middot; <?= e(APP_NAME) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><text y='14' font-size='14'>&#128200;</text></svg>">
    <link href="<?= e(url('assets/vendor/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(url('assets/vendor/css/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body<?= !empty($reopenModal) ? ' data-reopen-modal="' . e($reopenModal) . '"' : '' ?>>

<?php require __DIR__ . '/navbar.php'; ?>

<div class="app-shell">
    <!-- Desktop sidebar -->
    <aside class="app-sidebar d-none d-lg-flex flex-column">
        <?php require __DIR__ . '/sidebar.php'; ?>
    </aside>

    <!-- Mobile sidebar -->
    <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarMenu" aria-labelledby="sidebarMenuLabel">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title" id="sidebarMenuLabel"><?= e(APP_NAME) ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column p-0">
            <?php require __DIR__ . '/sidebar.php'; ?>
        </div>
    </div>

    <main class="app-main">
        <div class="page-head">
            <div>
                <?php if ($breadcrumbs): ?>
                    <nav aria-label="breadcrumb" class="mb-1">
                        <ol class="breadcrumb small mb-0">
                            <?php foreach ($breadcrumbs as $label => $link): ?>
                                <?php if ($link): ?>
                                    <?php /* url(), not the raw value: breadcrumbs are written
                                             project-relative ('tasks/index.php') and render on pages
                                             inside folders, where a bare href resolves against the
                                             current directory. */ ?>
                                    <li class="breadcrumb-item"><a href="<?= e(url($link)) ?>"><?= e($label) ?></a></li>
                                <?php else: ?>
                                    <li class="breadcrumb-item active" aria-current="page"><?= e($label) ?></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ol>
                    </nav>
                <?php endif; ?>
                <h1 class="page-title"><?= e($pageHeading ?: $pageTitle) ?></h1>
                <?php if (!empty($pageSubtitle)): ?>
                    <p class="text-secondary mb-0"><?= e($pageSubtitle) ?></p>
                <?php endif; ?>
            </div>
            <?php if (!empty($pageActions)): ?>
                <?php render_page_actions($pageActions); ?>
            <?php endif; ?>
        </div>

        <?php require __DIR__ . '/alerts.php'; ?>
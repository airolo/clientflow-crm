<?php
/**
 * Reusable sidebar navigation (shared by the desktop aside and mobile offcanvas).
 */

$navItems = [
    ['label' => 'Dashboard',  'icon' => 'bi-speedometer2',   'file' => 'dashboard.php',    'key' => 'dashboard'],
    ['divider' => 'CRM'],
    ['label' => 'Clients',    'icon' => 'bi-people-fill',    'file' => 'clients/index.php',  'key' => 'clients'],
    ['label' => 'Leads',      'icon' => 'bi-funnel-fill',    'file' => 'leads/index.php',    'key' => 'leads'],
    ['label' => 'Pipeline',   'icon' => 'bi-kanban-fill',    'file' => 'pipeline/index.php', 'key' => 'pipeline'],
    ['divider' => 'Productivity'],
    ['label' => 'Tasks',      'icon' => 'bi-check2-square',  'file' => 'tasks/index.php',    'key' => 'tasks'],
    ['label' => 'Activities', 'icon' => 'bi-clock-history', 'file' => 'activities/index.php', 'key' => 'activities'],
    ['divider' => 'Insight'],
    ['label' => 'Reports',    'icon' => 'bi-bar-chart-fill', 'file' => 'reports/index.php',  'key' => 'reports'],
];

if (is_admin()) {
    $binCount = $binCount ?? null;
    $navItems[] = ['divider' => 'Administration'];
    // bi-person-badge-fill, not bi-person-gear-fill: the latter does not exist
    // in Bootstrap Icons, so it rendered as a blank gap with no error anywhere.
    // tools\regression.ps1 checks every bi-* class against the vendored font.
    $navItems[] = ['label' => 'Users', 'icon' => 'bi-person-badge-fill', 'file' => 'admin/users.php', 'key' => 'users'];
    $navItems[] = [
        'label' => 'Recycle bin',
        'icon'  => 'bi-trash3-fill',
        'file'  => 'admin/recycle_bin.php',
        'key'   => 'recycle',
        'badge' => $binCount ?: null,
    ];
    // bi-gear-fill, not bi-sliders-fill / bi-sliders2-fill: neither of those exists
    // in the vendored icon set, and an icon class that is missing renders as a
    // blank gap with no error anywhere. tools\regression.ps1 checks every bi-*
    // class against the vendored css.
    $navItems[] = ['label' => 'Settings', 'icon' => 'bi-gear-fill', 'file' => 'admin/settings.php', 'key' => 'settings'];
}

$pendingTasks = $pendingTasks ?? null;
?>
<nav class="sidebar-nav">
    <?php foreach ($navItems as $item): ?>
        <?php if (!empty($item['divider'])): ?>
            <div class="sidebar-divider"><?= e($item['divider']) ?></div>
        <?php else: ?>
            <a class="sidebar-link<?= $activeNav === $item['key'] ? ' active' : '' ?>"
               href="<?= e(url($item['file'])) ?>">
                <i class="bi <?= e($item['icon']) ?>"></i>
                <span><?= e($item['label']) ?></span>
                <?php if ($item['key'] === 'tasks' && $pendingTasks): ?>
                    <span class="badge rounded-pill text-bg-danger ms-auto"><?= (int) $pendingTasks ?></span>
                <?php elseif (!empty($item['badge'])): ?>
                    <span class="badge rounded-pill text-bg-secondary ms-auto"><?= (int) $item['badge'] ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<div class="sidebar-foot">
    <div class="small text-secondary">
        <?= e(APP_NAME) ?> v1.0<br>
        Built with PHP, MySQL &amp; Bootstrap 5
    </div>
</div>
<?php
/**
 * Reusable sidebar navigation (shared by the desktop aside and mobile offcanvas).
 */

$navItems = [
    ['label' => 'Dashboard',  'icon' => 'bi-speedometer2',   'file' => 'index.php',    'key' => 'dashboard'],
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
    $navItems[] = ['divider' => 'Administration'];
    $navItems[] = ['label' => 'Users', 'icon' => 'bi-person-gear-fill', 'file' => 'admin/users.php', 'key' => 'users'];
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
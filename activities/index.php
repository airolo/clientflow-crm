<?php
/**
 * Activities - filterable interaction history across the whole CRM.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$search    = trim((string) ($_GET['search'] ?? ''));
$type      = (string) ($_GET['type'] ?? '');
$clientId  = (int) ($_GET['client_id'] ?? 0);
$leadId    = (int) ($_GET['lead_id'] ?? 0);
$userId    = (int) ($_GET['user_id'] ?? 0);
$dateFrom  = trim((string) ($_GET['date_from'] ?? ''));
$dateTo    = trim((string) ($_GET['date_to'] ?? ''));
$page      = current_page_number();

$result = activity_list([
    'search'     => $search,
    'type'       => $type,
    'client_id'  => $clientId,
    'lead_id'    => $leadId,
    'user_id'    => $userId,
    'date_from'  => $dateFrom,
    'date_to'    => $dateTo,
    'page'       => $page,
]);

$rows   = $result['rows'];
$total  = $result['total'];
$offset = $result['offset'];
$users  = user_all(true);
$mix    = report_activity_mix();

$pageTitle    = 'Activities';
$pageHeading  = 'Activities';
$pageSubtitle = $total . ' interaction' . ($total === 1 ? '' : 's') . ' recorded';
$activeNav    = 'activities';
$breadcrumbs  = ['Dashboard' => 'dashboard.php', 'Activities' => null];
$pageActions = [
    ['label' => 'Log activity', 'href' => 'activities/form.php', 'variant' => 'primary', 'icon' => 'bi-plus-lg'],
];

require __DIR__ . '/../views/header.php';
?>

<!-- Mix summary -->
<div class="row g-3 mb-3">
    <?php foreach ($mix as $entry): ?>
        <div class="col-6 col-md-3">
            <a href="<?= e(url_with(['type' => $type === $entry['type'] ? '' : $entry['type'], 'page' => 1])) ?>"
               class="text-decoration-none text-reset">
                <div class="stat-card">
                    <div class="stat-icon bg-tint-<?= e($entry['colour']) ?>">
                        <i class="bi <?= e(activity_icon($entry['type'])[1]) ?>"></i>
                    </div>
                    <div>
                        <div class="stat-label"><?= e($entry['label']) ?></div>
                        <div class="stat-value"><?= (int) $entry['total'] ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<?php render_filter_bar([
    'action' => 'activities/index.php',
    'fields' => [
        ['type' => 'search', 'name' => 'search', 'label' => 'Search', 'col' => 'col-12 col-md-3',
         'value' => $search, 'placeholder' => 'Summary or notes'],
        ['type' => 'select', 'name' => 'type', 'label' => 'Type', 'col' => 'col-6 col-md-2',
         'value' => $type, 'options' => activity_types(), 'options_label' => 'All types'],
        ['type' => 'select', 'name' => 'user_id', 'label' => 'Logged by', 'col' => 'col-6 col-md-2',
         'value' => $userId, 'options' => user_options(), 'options_label' => 'Anyone'],
        ['type' => 'date', 'name' => 'date_from', 'label' => 'From', 'col' => 'col-6 col-md-2',
         'value' => $dateFrom],
        ['type' => 'date', 'name' => 'date_to', 'label' => 'To', 'col' => 'col-6 col-md-2',
         'value' => $dateTo],
    ],
    'actions_col' => 'col-12 col-md-1',
]); ?>

<?php
// When arriving from a client/lead page, show that scope as a dismissible chip.
$scopeClient = $clientId > 0 ? client_find($clientId) : null;
$scopeLead   = $leadId > 0 ? lead_find($leadId) : null;
if ($scopeClient || $scopeLead):
?>
    <div class="alert alert-light border d-flex justify-content-between align-items-center py-2">
        <div>
            <i class="bi bi-funnel me-1"></i>
            Showing history for
            <strong><?= e($scopeClient['company_name'] ?? $scopeLead['lead_name']) ?></strong>
        </div>
        <a href="<?= url('activities/index.php') ?>" class="btn btn-sm btn-light border">Show all</a>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <?php
            $filtered = $search !== '' || $type !== '' || $userId > 0 || $dateFrom !== '' || $dateTo !== '';
            render_list_empty_state(
                $filtered,
                'bi-search', 'No matching activities', 'Try a different search term or clear the filters.', 'activities/index.php',
                'bi-clock-history', 'No activity logged yet',
                'Record calls, emails, meetings and notes as you work.',
                'activities/form.php', 'Log activity'
            );
            ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <?php render_th('Type', null, 'w-56'); ?>
                            <?php render_th('Summary'); ?>
                            <?php render_th('Related to'); ?>
                            <?php render_th('Logged by'); ?>
                            <?php render_th('When'); ?>
                            <?php render_th('Actions', null, 'row-actions'); ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $activity):
                        [$typeLabel, $typeIcon] = activity_icon($activity['type']);
                    ?>
                        <tr>
                            <td>
                                <span class="timeline-dot <?= e($activity['type']) ?>" style="position:static">
                                    <i class="bi <?= e($typeIcon) ?>"></i>
                                </span>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= e($activity['title']) ?></div>
                                <?php if ($activity['details']): ?>
                                    <div class="small text-secondary"><?= e(mb_strimwidth($activity['details'], 0, 110, '…')) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($activity['client_id']): ?>
                                    <a href="<?= url('clients/view.php') ?>?id=<?= (int) $activity['client_id'] ?>" class="small text-reset">
                                        <i class="bi bi-building me-1"></i><?= e($activity['company_name']) ?>
                                    </a>
                                <?php elseif ($activity['lead_id']): ?>
                                    <a href="<?= url('leads/view.php') ?>?id=<?= (int) $activity['lead_id'] ?>" class="small text-reset">
                                        <i class="bi bi-funnel me-1"></i><?= e($activity['lead_name']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="small text-secondary">Internal</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="small d-inline-flex align-items-center gap-1">
                                    <span class="avatar avatar-xs" style="background:<?= e(avatar_colour($activity['owner_name'] ?? '?')) ?>">
                                        <?= e(initials($activity['owner_name'] ?? '?')) ?>
                                    </span>
                                    <?= e($activity['owner_name'] ?: 'Unknown') ?>
                                </span>
                            </td>
                            <td>
                                <div class="small"><?= e(date('d M Y', strtotime((string) $activity['created_at']))) ?></div>
                                <div class="small text-secondary" title="<?= e($activity['created_at']) ?>">
                                    <?= e(time_ago($activity['created_at'])) ?>
                                </div>
                            </td>
                            <td class="row-actions">
                                <div class="btn-group btn-group-sm">
                                    <?php if (can_manage(['created_by' => $activity['created_by'], 'assigned_to' => 0])): ?>
                                        <a href="<?= url('activities/form.php') ?>?id=<?= (int) $activity['id'] ?>" class="btn btn-outline-secondary"
                                           title="Edit" aria-label="Edit <?= e($activity['title']) ?>"><i class="bi bi-pencil"></i></a>
                                    <?php endif; ?>
                                    <?php if (can_view_activity($activity)): ?>
                                    <?php render_post_form_open([
                                        'action_url' => 'activities/action.php',
                                        'action' => 'delete',
                                        'id' => (int) $activity['id'],
                                        'return' => 'activities/index.php',
                                    ]); ?>
                                    <button type="submit" class="btn btn-outline-danger"
                                            data-confirm="Delete this activity? It moves to the recycle bin, where an administrator can restore it."
                                            title="Delete" aria-label="Delete <?= e($activity['title']) ?>"><i class="bi bi-trash"></i></button>
                                    <?php render_post_form_close(); ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php render_table_footer($total, $offset, count($rows)); ?>

<?php require __DIR__ . '/../views/footer.php'; ?>

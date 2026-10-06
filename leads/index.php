<?php
/**
 * Leads - searchable, filterable list with inline status updates.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$search     = trim((string) ($_GET['search'] ?? ''));
$status     = (string) ($_GET['status'] ?? '');
$source     = (string) ($_GET['source'] ?? '');
$assignedTo = (int) ($_GET['assigned_to'] ?? 0);
$page       = current_page_number();

$result = lead_list([
    'search'      => $search,
    'status'      => $status,
    'source'      => $source,
    'assigned_to' => $assignedTo,
    'sort'        => $_GET['sort'] ?? '',
    'dir'         => $_GET['dir'] ?? '',
    'page'        => $page,
]);

$rows    = $result['rows'];
$total   = $result['total'];
$offset  = $result['offset'];
$users   = user_all(true);
$columns = lead_sort_columns();

$pageTitle    = 'Leads';
$pageHeading  = 'Leads';
$pageSubtitle = $total . ' lead' . ($total === 1 ? '' : 's') . ' matching your filters';
$activeNav    = 'leads';
$breadcrumbs  = ['Dashboard' => 'index.php', 'Leads' => null];
$pageActions = [
    ['label' => 'Add lead', 'href' => 'leads/form.php', 'variant' => 'primary', 'icon' => 'bi-plus-lg'],
];

require __DIR__ . '/../views/header.php';
?>

<?php render_filter_bar([
    'action' => 'leads/index.php',
    'fields' => [
        ['type' => 'search', 'name' => 'search', 'label' => 'Search', 'col' => 'col-12 col-md-4',
         'value' => $search, 'placeholder' => 'Name, company, email or phone'],
        ['type' => 'select', 'name' => 'status', 'label' => 'Status', 'col' => 'col-6 col-md-2',
         'value' => $status, 'options' => lead_statuses(), 'options_label' => 'All'],
        ['type' => 'select', 'name' => 'source', 'label' => 'Source', 'col' => 'col-6 col-md-2',
         'value' => $source, 'options' => lead_sources(), 'options_label' => 'All'],
        ['type' => 'select', 'name' => 'assigned_to', 'label' => 'Assigned to', 'col' => 'col-6 col-md-2',
         'value' => $assignedTo, 'options' => user_options(), 'options_label' => 'Anyone'],
    ],
    'actions_col' => 'col-6 col-md-2',
]); ?>

<div class="card">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <?php
            $filtered = $search !== '' || $status !== '' || $source !== '' || $assignedTo > 0;
            render_list_empty_state(
                $filtered,
                'bi-search', 'No matching leads', 'Try a different search term or clear the filters.', 'leads/index.php',
                'bi-funnel', 'No leads yet', 'Capture your first prospect to start building the funnel.',
                'leads/form.php', 'Add lead'
            );
            ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <?php render_th('Lead', sort_href('name')); ?>
                            <?php render_th('Company', sort_href('company')); ?>
                            <?php render_th('Source', sort_href('source')); ?>
                            <?php render_th('Value', sort_href('value')); ?>
                            <?php render_th('Status', sort_href('status')); ?>
                            <?php render_th('Assigned to', sort_href('owner')); ?>
                            <?php render_th('Created', sort_href('created')); ?>
                            <?php render_th('Actions', null, 'row-actions'); ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $lead): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar avatar-sm" style="background:<?= e(avatar_colour($lead['lead_name'])) ?>">
                                        <?= e(initials($lead['lead_name'])) ?>
                                    </span>
                                    <div class="min-w-0">
                                        <a href="<?= url('leads/view.php') ?>?id=<?= (int) $lead['id'] ?>" class="fw-semibold text-reset">
                                            <?= e($lead['lead_name']) ?>
                                        </a>
                                        <div class="small text-secondary"><?= e($lead['email'] ?: 'No email') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?= e($lead['company'] ?: '—') ?></td>
                            <td><span class="small text-secondary"><?= e(pretty($lead['lead_source'])) ?></span></td>
                            <td class="mono fw-semibold"><?= e(money($lead['estimated_value'])) ?></td>
                            <td><?= lead_status_badge($lead['status']) ?></td>
                            <td><span class="small"><?= e($lead['owner_name'] ?: 'Unassigned') ?></span></td>
                            <td><span class="small text-secondary"><?= e(nice_date($lead['created_at'])) ?></span></td>
                            <td class="row-actions">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= url('leads/view.php') ?>?id=<?= (int) $lead['id'] ?>" class="btn btn-outline-secondary"
                                       title="View" aria-label="View <?= e($lead['lead_name']) ?>"><i class="bi bi-eye"></i></a>
                                    <a href="<?= url('leads/form.php') ?>?id=<?= (int) $lead['id'] ?>" class="btn btn-outline-primary"
                                       title="Edit" aria-label="Edit <?= e($lead['lead_name']) ?>"><i class="bi bi-pencil"></i></a>
                                    <a href="<?= url('pipeline/form.php') ?>?lead_id=<?= (int) $lead['id'] ?>" class="btn btn-outline-success"
                                       title="Create deal" aria-label="Create a deal from <?= e($lead['lead_name']) ?>"><i class="bi bi-kanban"></i></a>
                                    <?php render_post_form_open([
                                        'action_url' => 'leads/action.php',
                                        'action' => 'delete',
                                        'id' => (int) $lead['id'],
                                        'return' => 'leads/index.php',
                                    ]); ?>
                                    <button type="submit" class="btn btn-outline-danger"
                                            data-confirm="Delete lead <?= e($lead['lead_name']) ?>? Its tasks and activity history will also be removed."
                                            title="Delete" aria-label="Delete <?= e($lead['lead_name']) ?>"><i class="bi bi-trash"></i></button>
                                    <?php render_post_form_close(); ?>
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

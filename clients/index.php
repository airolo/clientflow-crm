<?php
/**
 * Clients - searchable, filterable, sortable, paginated list.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$search     = trim((string) ($_GET['search'] ?? ''));
$status     = (string) ($_GET['status'] ?? '');
$assignedTo = (int) ($_GET['assigned_to'] ?? 0);
$page       = current_page_number();

$result = client_list([
    'search'      => $search,
    'status'      => $status,
    'assigned_to' => $assignedTo,
    'sort'        => $_GET['sort'] ?? '',
    'dir'         => $_GET['dir'] ?? '',
    'page'        => $page,
]);

$rows       = $result['rows'];
$total      = $result['total'];
$offset     = $result['offset'];
$users      = user_all(true);
$columns    = client_sort_columns();

$pageTitle   = 'Clients';
$pageHeading = 'Clients';
$pageSubtitle = $total . ' client account' . ($total === 1 ? '' : 's') . ' on file';
$activeNav   = 'clients';
$breadcrumbs = ['Dashboard' => 'dashboard.php', 'Clients' => null];
$pageActions = [
    ['label' => 'Add client', 'href' => 'clients/form.php', 'variant' => 'primary', 'icon' => 'bi-plus-lg'],
];

require __DIR__ . '/../views/header.php';
?>

<?php render_filter_bar([
    'action' => 'clients/index.php',
    'layout' => '',
    'fields' => [
        ['type' => 'search', 'name' => 'search', 'label' => 'Search', 'col' => 'col-12 col-md-4',
         'value' => $search, 'placeholder' => 'Company, contact, email or phone'],
        ['type' => 'select', 'name' => 'status', 'label' => 'Status', 'col' => 'col-6 col-md-3',
         'value' => $status, 'options' => client_statuses(), 'options_label' => 'All statuses'],
        ['type' => 'select', 'name' => 'assigned_to', 'label' => 'Assigned to', 'col' => 'col-6 col-md-3',
         'value' => $assignedTo, 'options' => user_options(), 'options_label' => 'Anyone'],
    ],
]); ?>

<!-- ---------- Results ---------- -->
<div class="card">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <?php
            $filtered = $search !== '' || $status !== '' || $assignedTo > 0;
            render_list_empty_state(
                $filtered,
                'bi-search', 'No matching clients', 'Try widening your search or clearing the filters.',
                'clients/index.php',
                'bi-people', 'No clients yet', 'Add your first client account to get started.',
                'clients/form.php', 'Add client'
            );
            ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <?php render_th('Company', sort_href('company')); ?>
                            <?php render_th('Contact', sort_href('contact')); ?>
                            <?php render_th('Contact details'); ?>
                            <?php render_th('Status', sort_href('status')); ?>
                            <?php render_th('Assigned to', sort_href('owner')); ?>
                            <?php render_th('Actions', null, 'row-actions'); ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $client): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar avatar-sm" style="background:<?= e(avatar_colour($client['company_name'])) ?>">
                                        <?= e(initials($client['company_name'])) ?>
                                    </span>
                                    <div class="min-w-0">
                                        <a href="<?= url('clients/view.php') ?>?id=<?= (int) $client['id'] ?>" class="fw-semibold text-reset">
                                            <?= e($client['company_name']) ?>
                                        </a>
                                        <div class="small text-secondary"><?= e($client['address'] ?: 'No address on file') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?= e($client['contact_person']) ?></td>
                            <td>
                                <?php if ($client['email']): ?>
                                    <a href="mailto:<?= e($client['email']) ?>" class="d-block small"><?= e($client['email']) ?></a>
                                <?php endif; ?>
                                <?php if ($client['phone']): ?>
                                    <span class="small text-secondary"><?= e($client['phone']) ?></span>
                                <?php endif; ?>
                                <?php if (!$client['email'] && !$client['phone']): ?>
                                    <span class="small text-secondary">Not provided</span>
                                <?php endif; ?>
                            </td>
                            <td><?= client_status_badge($client['status']) ?></td>
                            <td>
                                <?php if ($client['owner_name']): ?>
                                    <span class="small"><?= e($client['owner_name']) ?></span>
                                <?php else: ?>
                                    <span class="small text-secondary">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="row-actions">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= url('clients/view.php') ?>?id=<?= (int) $client['id'] ?>"
                                       class="btn btn-outline-secondary" title="View" aria-label="View <?= e($client['company_name']) ?>">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if (can_manage($client)): ?>
                                    <a href="<?= url('clients/form.php') ?>?id=<?= (int) $client['id'] ?>"
                                       class="btn btn-outline-primary" title="Edit" aria-label="Edit <?= e($client['company_name']) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php render_post_form_open([
                                        'action_url' => 'clients/action.php',
                                        'action' => 'delete',
                                        'id' => (int) $client['id'],
                                        'return' => 'clients/index.php',
                                    ]); ?>
                                    <button type="submit" class="btn btn-outline-danger"
                                            data-confirm="Delete <?= e($client['company_name']) ?>? Its deals, tasks and activity history move to the recycle bin and can be restored by an administrator."
                                            title="Delete" aria-label="Delete <?= e($client['company_name']) ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
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

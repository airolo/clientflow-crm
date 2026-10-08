<?php
/**
 * Tasks - the follow-up list with filters, sorting and quick completion.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$search     = trim((string) ($_GET['search'] ?? ''));
$status     = (string) ($_GET['status'] ?? '');
$priority   = (string) ($_GET['priority'] ?? '');
$assignedTo = (int) ($_GET['assigned_to'] ?? 0);
$overdue    = !empty($_GET['overdue']);
$page       = current_page_number();

$result = task_list([
    'search'      => $search,
    'status'      => $status,
    'priority'    => $priority,
    'assigned_to' => $assignedTo,
    'overdue'     => $overdue,
    'sort'        => $_GET['sort'] ?? '',
    'dir'         => $_GET['dir'] ?? '',
    'page'        => $page,
]);

$rows    = $result['rows'];
$total   = $result['total'];
$offset  = $result['offset'];
$users   = user_all(true);
$columns = task_sort_columns();
$overdueCount = task_overdue_count();

$pageTitle    = 'Tasks';
$pageHeading  = 'Tasks & follow-ups';
$pageSubtitle = $total . ' task' . ($total === 1 ? '' : 's') . ' matching your filters'
    . ($overdueCount > 0 ? ' · ' . $overdueCount . ' overdue' : '');
$activeNav    = 'tasks';
$breadcrumbs  = ['Dashboard' => 'dashboard.php', 'Tasks' => null];
$pageActions = [
    ['label' => 'Add task', 'href' => 'tasks/form.php', 'variant' => 'primary', 'icon' => 'bi-plus-lg'],
    ['label' => 'Export CSV', 'href' => 'export.php?type=task', 'variant' => 'outline-secondary', 'icon' => 'bi-download'],
];

require __DIR__ . '/../views/header.php';
?>

<!-- Quick status tabs -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php
    $tabs = [
        ''            => 'All',
        'pending'     => 'Pending',
        'in_progress' => 'In progress',
        'completed'   => 'Completed',
];
    $statusCounts = task_count_by_status();
    $allTaskCount = array_sum(array_map('intval', $statusCounts));
    ?>
    <?php foreach ($tabs as $value => $label):
        $tabCount = $value === '' ? $allTaskCount : (int) ($statusCounts[$value] ?? 0);
    ?>
        <a href="<?= e(url_with(['status' => $value, 'page' => 1])) ?>"
           class="btn btn-sm <?= $status === $value ? 'btn-primary' : 'btn-light border' ?>">
            <?= e($label) ?>
            <span class="badge text-bg-<?= $status === $value ? 'light' : 'secondary' ?> ms-1">
                <?= $tabCount ?>
            </span>
        </a>
    <?php endforeach; ?>

    <a href="<?= e(url_with(['overdue' => $overdue ? '' : '1', 'page' => 1])) ?>"
       class="btn btn-sm <?= $overdue ? 'btn-danger' : 'btn-light border' ?>">
        <i class="bi bi-exclamation-triangle me-1"></i>Overdue only
    </a>
</div>

<?php render_filter_bar([
    'action' => 'tasks/index.php',
    // Status is chosen with the tabs above, and the overdue toggle is a
    // separate button, so both are carried through rather than re-picked.
    'hidden' => ['status' => $status, 'overdue' => $overdue ? '1' : ''],
    'fields' => [
        ['type' => 'search', 'name' => 'search', 'label' => 'Search', 'col' => 'col-12 col-md-5',
         'value' => $search, 'placeholder' => 'Title, description, client or lead'],
        ['type' => 'select', 'name' => 'priority', 'label' => 'Priority', 'col' => 'col-6 col-md-3',
         'value' => $priority, 'options' => task_priorities(), 'options_label' => 'Any'],
        ['type' => 'select', 'name' => 'assigned_to', 'label' => 'Assigned to', 'col' => 'col-6 col-md-3',
         'value' => $assignedTo, 'options' => user_options(), 'options_label' => 'Anyone'],
    ],
    'actions_col' => 'col-12 col-md-1',
]); ?>

<div class="card">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <?php
            $filtered = $search !== '' || $status !== '' || $priority !== '' || $assignedTo > 0 || $overdue;
            render_list_empty_state(
                $filtered,
                'bi-search', 'No matching tasks', 'Nothing matches these filters right now.', 'tasks/index.php',
                'bi-check2-square', 'No tasks yet', 'Create follow-ups so every conversation has a next step.',
                'tasks/form.php', 'Add task'
            );
            ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <?php render_th('Done', null, 'w-44'); ?>
                            <?php render_th('Task', sort_href('title')); ?>
                            <?php render_th('Related to'); ?>
                            <?php render_th('Priority', sort_href('priority')); ?>
                            <?php render_th('Due date', sort_href('due')); ?>
                            <?php render_th('Status', sort_href('status')); ?>
                            <?php render_th('Assigned to', sort_href('owner')); ?>
                            <?php render_th('Actions', null, 'row-actions'); ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $task):
                        $isLate = $task['status'] !== 'completed' && $task['due_date'] && $task['due_date'] < date('Y-m-d');
                        $related = $task['company_name'] ?: ($task['lead_name'] ?: null);
                    ?>
                        <tr>
                            <td>
                                <?php
                                $doneLabel = $task['status'] === 'completed' ? 'Reopen task' : 'Mark complete';
                                render_post_form_open([
                                    'action_url' => 'tasks/action.php',
                                    'action' => $task['status'] === 'completed' ? 'reopen' : 'complete',
                                    'id' => (int) $task['id'],
                                    'return' => 'tasks/index.php',
                                ]);
                                ?>
                                <button type="submit"
                                        class="btn btn-sm <?= $task['status'] === 'completed' ? 'btn-light border' : 'btn-outline-success' ?>"
                                        title="<?= e($doneLabel) ?>" aria-label="<?= e($doneLabel) ?>: <?= e($task['title']) ?>">
                                    <i class="bi <?= $task['status'] === 'completed' ? 'bi-arrow-counterclockwise' : 'bi-check-lg' ?>"></i>
                                </button>
                                <?php render_post_form_close(); ?>
                            </td>
                            <td>
                                <div class="fw-semibold <?= $task['status'] === 'completed' ? 'text-decoration-line-through text-secondary' : '' ?>">
                                    <?= e($task['title']) ?>
                                </div>
                                <?php if ($task['description']): ?>
                                    <div class="small text-secondary"><?= e(mb_strimwidth($task['description'], 0, 90, '…')) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($task['client_id']): ?>
                                    <a href="<?= url('clients/view.php') ?>?id=<?= (int) $task['client_id'] ?>" class="small text-reset">
                                        <i class="bi bi-building me-1"></i><?= e($related) ?>
                                    </a>
                                <?php elseif ($task['lead_id']): ?>
                                    <a href="<?= url('leads/view.php') ?>?id=<?= (int) $task['lead_id'] ?>" class="small text-reset">
                                        <i class="bi bi-funnel me-1"></i><?= e($related) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="small text-secondary"><i class="bi bi-house me-1"></i>Internal</span>
                                <?php endif; ?>
                            </td>
                            <td><?= priority_badge($task['priority']) ?></td>
                            <td>
                                <span class="small <?= $isLate ? 'text-danger fw-semibold' : 'text-secondary' ?>">
                                    <?php if ($isLate): ?><i class="bi bi-exclamation-circle me-1"></i><?php endif; ?>
                                    <?= e(nice_date($task['due_date'])) ?>
                                </span>
                            </td>
                            <td><?= task_status_badge($task['status']) ?></td>
                            <td><span class="small"><?= e($task['owner_name'] ?: 'Unassigned') ?></span></td>
                            <td class="row-actions">
                                  <div class="btn-group btn-group-sm">
                                      <?php if (can_manage($task)): ?>
                                      <a href="<?= url('tasks/form.php') ?>?id=<?= (int) $task['id'] ?>" class="btn btn-outline-secondary"
                                         title="Edit" aria-label="Edit <?= e($task['title']) ?>"><i class="bi bi-pencil"></i></a>
                                      <?php render_post_form_open([
                                          'action_url' => 'tasks/action.php',
                                          'action' => 'delete',
                                          'id' => (int) $task['id'],
                                          'return' => 'tasks/index.php',
                                      ]); ?>
                                      <button type="submit" class="btn btn-outline-danger"
                                              data-confirm="Delete task &quot;<?= e($task['title']) ?>&quot;? It moves to the recycle bin, where an administrator can restore it."
                                              title="Delete" aria-label="Delete <?= e($task['title']) ?>"><i class="bi bi-trash"></i></button>
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

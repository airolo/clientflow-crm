<?php
/**
 * Tasks - the follow-up list with filters, sorting and quick completion.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
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
$userId  = (int) current_user_id();
$overdueCount = task_overdue_count();
$sidebarPendingTasks = task_count_open_for_sidebar($userId);

$pageTitle    = 'Tasks';
$pageHeading  = 'Tasks & follow-ups';
$pageSubtitle = $total . ' task' . ($total === 1 ? '' : 's') . ' matching your filters'
    . ($overdueCount > 0 ? ' · ' . $overdueCount . ' overdue' : '');
$activeNav    = 'tasks';
$breadcrumbs  = ['Dashboard' => 'index.php', 'Tasks' => null];
$pageActions  = '<a href="task_form.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add task</a>';

require __DIR__ . '/includes/header.php';
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
    $statusCounts = db()->query('SELECT status, COUNT(*) AS total FROM tasks GROUP BY status')
        ->fetchAll(PDO::FETCH_KEY_PAIR);
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

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body filter-bar">
        <form method="get" action="tasks.php" class="row g-2 align-items-end">
            <input type="hidden" name="status" value="<?= e($status) ?>">
            <input type="hidden" name="overdue" value="<?= $overdue ? '1' : '' ?>">
            <div class="col-12 col-md-5">
                <label for="search" class="form-label">Search</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="search" name="search" id="search" class="form-control"
                           value="<?= e($search) ?>" placeholder="Title, description, client or lead">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <label for="priority" class="form-label">Priority</label>
                <select name="priority" id="priority" class="form-select">
                    <option value="">Any</option>
                    <?= select_options(task_priorities(), $priority) ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="assigned_to" class="form-label">Assigned to</label>
                <select name="assigned_to" id="assigned_to" class="form-select">
                    <option value="">Anyone</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>" <?= $assignedTo === (int) $user['id'] ? 'selected' : '' ?>>
                            <?= e($user['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-1 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1" title="Filter"><i class="bi bi-funnel"></i></button>
                <a href="tasks.php" class="btn btn-light border" title="Clear"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <?php
            $filtered = $search !== '' || $status !== '' || $priority !== '' || $assignedTo > 0 || $overdue;
            echo $filtered
                ? empty_state('bi-search', 'No matching tasks', 'Nothing matches these filters right now.', 'tasks.php', 'Clear filters')
                : empty_state('bi-check2-square', 'No tasks yet', 'Create follow-ups so every conversation has a next step.', 'task_form.php', 'Add task');
            ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:44px"></th>
                            <th><?= sort_link('Task', 'title', $columns, 'due') ?></th>
                            <th>Related to</th>
                            <th><?= sort_link('Priority', 'priority', $columns, 'due') ?></th>
                            <th><?= sort_link('Due date', 'due', $columns, 'due') ?></th>
                            <th><?= sort_link('Status', 'status', $columns, 'due') ?></th>
                            <th><?= sort_link('Assigned to', 'owner', $columns, 'due') ?></th>
                            <th class="row-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $task):
                        $isLate = $task['status'] !== 'completed' && $task['due_date'] && $task['due_date'] < date('Y-m-d');
                        $related = $task['company_name'] ?: ($task['lead_name'] ?: null);
                    ?>
                        <tr>
                            <td>
                                <form method="post" action="task_action.php" class="m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="<?= $task['status'] === 'completed' ? 'reopen' : 'complete' ?>">
                                    <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                                    <input type="hidden" name="return" value="tasks.php">
                                    <button type="submit" class="btn btn-sm <?= $task['status'] === 'completed' ? 'btn-light border' : 'btn-outline-success' ?>"
                                            title="<?= $task['status'] === 'completed' ? 'Reopen task' : 'Mark complete' ?>">
                                        <i class="bi <?= $task['status'] === 'completed' ? 'bi-arrow-counterclockwise' : 'bi-check-lg' ?>"></i>
                                    </button>
                                </form>
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
                                    <a href="client_view.php?id=<?= (int) $task['client_id'] ?>" class="small text-reset">
                                        <i class="bi bi-building me-1"></i><?= e($related) ?>
                                    </a>
                                <?php elseif ($task['lead_id']): ?>
                                    <a href="lead_view.php?id=<?= (int) $task['lead_id'] ?>" class="small text-reset">
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
                                    <a href="task_form.php?id=<?= (int) $task['id'] ?>" class="btn btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <form method="post" action="task_action.php" class="m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                                        <input type="hidden" name="return" value="tasks.php">
                                        <button type="submit" class="btn btn-outline-danger"
                                                data-confirm="Delete task &quot;<?= e($task['title']) ?>&quot;?"
                                                title="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
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

<?php if ($rows): ?>
    <div class="table-footer">
        <div><?= result_summary($total, $offset, count($rows)) ?></div>
        <?= render_pagination($total) ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
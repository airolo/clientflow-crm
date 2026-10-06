<?php
/**
 * Leads - searchable, filterable list with inline status updates.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
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
$sidebarPendingTasks = task_count_open_for_sidebar((int) current_user_id());

$pageTitle    = 'Leads';
$pageHeading  = 'Leads';
$pageSubtitle = $total . ' lead' . ($total === 1 ? '' : 's') . ' matching your filters';
$activeNav    = 'leads';
$breadcrumbs  = ['Dashboard' => 'index.php', 'Leads' => null];
$pageActions  = '<a href="lead_form.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add lead</a>';

require __DIR__ . '/includes/header.php';
?>

<div class="card mb-3">
    <div class="card-body filter-bar">
        <form method="get" action="leads.php" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label for="search" class="form-label">Search</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="search" name="search" id="search" class="form-control"
                           value="<?= e($search) ?>" placeholder="Name, company, email or phone">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <label for="status" class="form-label">Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">All</option>
                    <?= select_options(lead_statuses(), $status) ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="source" class="form-label">Source</label>
                <select name="source" id="source" class="form-select">
                    <option value="">All</option>
                    <?= select_options(lead_sources(), $source) ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
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
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="leads.php" class="btn btn-light border" title="Clear filters"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <?php
            $filtered = $search !== '' || $status !== '' || $source !== '' || $assignedTo > 0;
            echo $filtered
                ? empty_state('bi-search', 'No matching leads', 'Try a different search term or clear the filters.', 'leads.php', 'Clear filters')
                : empty_state('bi-funnel', 'No leads yet', 'Capture your first prospect to start building the funnel.', 'lead_form.php', 'Add lead');
            ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= sort_link('Lead', 'name', $columns, 'created') ?></th>
                            <th><?= sort_link('Company', 'company', $columns, 'created') ?></th>
                            <th><?= sort_link('Source', 'source', $columns, 'created') ?></th>
                            <th><?= sort_link('Value', 'value', $columns, 'created') ?></th>
                            <th><?= sort_link('Status', 'status', $columns, 'created') ?></th>
                            <th><?= sort_link('Assigned to', 'owner', $columns, 'created') ?></th>
                            <th><?= sort_link('Created', 'created', $columns, 'created') ?></th>
                            <th class="row-actions">Actions</th>
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
                                        <a href="lead_view.php?id=<?= (int) $lead['id'] ?>" class="fw-semibold text-reset">
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
                            <td>
                                <span class="small"><?= e($lead['owner_name'] ?: 'Unassigned') ?></span>
                            </td>
                            <td><span class="small text-secondary"><?= e(nice_date($lead['created_at'])) ?></span></td>
                            <td class="row-actions">
                                <div class="btn-group btn-group-sm">
                                    <a href="lead_view.php?id=<?= (int) $lead['id'] ?>" class="btn btn-outline-secondary" title="View"><i class="bi bi-eye"></i></a>
                                    <a href="lead_form.php?id=<?= (int) $lead['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <a href="deal_form.php?lead_id=<?= (int) $lead['id'] ?>" class="btn btn-outline-success" title="Create deal"><i class="bi bi-kanban"></i></a>
                                    <form method="post" action="lead_action.php" class="m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
                                        <input type="hidden" name="return" value="leads.php">
                                        <button type="submit" class="btn btn-outline-danger"
                                                data-confirm="Delete lead <?= e($lead['lead_name']) ?>? Its tasks and activity history will also be removed."
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
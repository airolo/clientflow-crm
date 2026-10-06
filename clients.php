<?php
/**
 * Clients - searchable, filterable, sortable, paginated list.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
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
$userId     = (int) current_user_id();
$sidebarPendingTasks = task_count_open_for_sidebar($userId);

$pageTitle   = 'Clients';
$pageHeading = 'Clients';
$pageSubtitle = $total . ' client account' . ($total === 1 ? '' : 's') . ' on file';
$activeNav   = 'clients';
$breadcrumbs = ['Dashboard' => 'index.php', 'Clients' => null];
$pageActions = '<a href="client_form.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add client</a>';

require __DIR__ . '/includes/header.php';
?>

<!-- ---------- Filters ---------- -->
<div class="card mb-3">
    <div class="card-body filter-bar">
        <form method="get" action="clients.php" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label for="search" class="form-label">Search</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="search" name="search" id="search" class="form-control"
                           value="<?= e($search) ?>" placeholder="Company, contact, email or phone">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <label for="status" class="form-label">Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">All statuses</option>
                    <?= select_options(client_statuses(), $status) ?>
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
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="clients.php" class="btn btn-light border" title="Clear filters"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- ---------- Results ---------- -->
<div class="card">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <?php
            $filtered = $search !== '' || $status !== '' || $assignedTo > 0;
            echo $filtered
                ? empty_state('bi-search', 'No matching clients', 'Try widening your search or clearing the filters.', 'clients.php', 'Clear filters')
                : empty_state('bi-people', 'No clients yet', 'Add your first client account to get started.', 'client_form.php', 'Add client');
            ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= sort_link('Company', 'company', $columns, 'created') ?></th>
                            <th><?= sort_link('Contact', 'contact', $columns, 'created') ?></th>
                            <th>Contact details</th>
                            <th><?= sort_link('Status', 'status', $columns, 'created') ?></th>
                            <th><?= sort_link('Assigned to', 'owner', $columns, 'created') ?></th>
                            <th class="row-actions">Actions</th>
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
                                        <a href="client_view.php?id=<?= (int) $client['id'] ?>" class="fw-semibold text-reset">
                                            <?= e($client['company_name']) ?>
                                        </a>
                                        <div class="small text-secondary"><?= e($client['address'] ?: 'No address on file') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?= e($client['contact_person']) ?>
                            </td>
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
                                    <a href="client_view.php?id=<?= (int) $client['id'] ?>"
                                       class="btn btn-outline-secondary" title="View"><i class="bi bi-eye"></i></a>
                                    <a href="client_form.php?id=<?= (int) $client['id'] ?>"
                                       class="btn btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <form method="post" action="client_action.php" class="m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $client['id'] ?>">
                                        <input type="hidden" name="return" value="clients.php">
                                        <button type="submit" class="btn btn-outline-danger"
                                                data-confirm="Delete <?= e($client['company_name']) ?>? This also removes its deals, tasks and activity history."
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
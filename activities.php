<?php
/**
 * Activities - filterable interaction history across the whole CRM.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
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
$sidebarPendingTasks = task_count_open_for_sidebar((int) current_user_id());

$pageTitle    = 'Activities';
$pageHeading  = 'Activities';
$pageSubtitle = $total . ' interaction' . ($total === 1 ? '' : 's') . ' recorded';
$activeNav    = 'activities';
$breadcrumbs  = ['Dashboard' => 'index.php', 'Activities' => null];
$pageActions  = '<a href="activity_form.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Log activity</a>';

require __DIR__ . '/includes/header.php';
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

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body filter-bar">
        <form method="get" action="activities.php" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label for="search" class="form-label">Search</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="search" name="search" id="search" class="form-control"
                           value="<?= e($search) ?>" placeholder="Summary or notes">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <label for="type" class="form-label">Type</label>
                <select name="type" id="type" class="form-select">
                    <option value="">All types</option>
                    <?= select_options(activity_types(), $type) ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="user_id" class="form-label">Logged by</label>
                <select name="user_id" id="user_id" class="form-select">
                    <option value="">Anyone</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>" <?= $userId === (int) $user['id'] ? 'selected' : '' ?>>
                            <?= e($user['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="date_from" class="form-label">From</label>
                <input type="date" name="date_from" id="date_from" class="form-control" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label for="date_to" class="form-label">To</label>
                <input type="date" name="date_to" id="date_to" class="form-control" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-12 col-md-1 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1" title="Filter"><i class="bi bi-funnel"></i></button>
                <a href="activities.php" class="btn btn-light border" title="Clear"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

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
        <a href="activities.php" class="btn btn-sm btn-light border">Show all</a>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <?php
            $filtered = $search !== '' || $type !== '' || $userId > 0 || $dateFrom !== '' || $dateTo !== '';
            echo $filtered
                ? empty_state('bi-search', 'No matching activities', 'Try a different search term or clear the filters.', 'activities.php', 'Clear filters')
                : empty_state('bi-clock-history', 'No activity logged yet', 'Record calls, emails, meetings and notes as you work.', 'activity_form.php', 'Log activity');
            ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:56px">Type</th>
                            <th>Summary</th>
                            <th>Related to</th>
                            <th>Logged by</th>
                            <th>When</th>
                            <th class="row-actions">Actions</th>
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
                                    <a href="client_view.php?id=<?= (int) $activity['client_id'] ?>" class="small text-reset">
                                        <i class="bi bi-building me-1"></i><?= e($activity['company_name']) ?>
                                    </a>
                                <?php elseif ($activity['lead_id']): ?>
                                    <a href="lead_view.php?id=<?= (int) $activity['lead_id'] ?>" class="small text-reset">
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
                                        <a href="activity_form.php?id=<?= (int) $activity['id'] ?>" class="btn btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <?php endif; ?>
                                    <form method="post" action="activity_action.php" class="m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $activity['id'] ?>">
                                        <input type="hidden" name="return" value="activities.php">
                                        <button type="submit" class="btn btn-outline-danger"
                                                data-confirm="Delete this activity? This cannot be undone."
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
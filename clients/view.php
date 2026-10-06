<?php
/**
 * Client detail - profile, deals, tasks and the interaction history.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$id     = (int) ($_GET['id'] ?? 0);
$client = $id > 0 ? client_find($id) : null;

if (!$client) {
    flash_error('That client could not be found.', 'clients/index.php');
}

$deals      = client_deals($id);
$tasks      = client_tasks($id);
$activities = client_activities($id, 15);
$summary    = client_deal_summary($id);
$canEdit    = can_manage($client);

$pageTitle   = $client['company_name'];
$pageHeading = $client['company_name'];
$pageSubtitle = $client['contact_person'] . ' · ' . ($client['email'] ?: 'no email on file');
$activeNav   = 'clients';
$breadcrumbs = ['Dashboard' => 'index.php', 'Clients' => 'clients/index.php', $client['company_name'] => null];
$pageActions = [
    ['label' => 'Log activity', 'href' => "activity_form.php?client_id=$id", 'icon' => 'bi-plus-lg'],
    ['label' => 'Add task', 'href' => "task_form.php?client_id=$id", 'variant' => 'outline-primary', 'icon' => 'bi-check2-square'],
];
if ($canEdit) {
    $pageActions[] = ['label' => 'Edit', 'href' => "client_form.php?id=$id", 'variant' => 'outline-secondary', 'icon' => 'bi-pencil'];
}

require __DIR__ . '/../views/header.php';
?>

<div class="row g-3">
    <!-- ---------- Left column: profile + deals ---------- -->
    <div class="col-12 col-lg-4">
        <div class="card mb-3">
            <div class="card-body text-center">
                <span class="avatar avatar-lg mb-2" style="background:<?= e(avatar_colour($client['company_name'])) ?>">
                    <?= e(initials($client['company_name'])) ?>
                </span>
                <h2 class="h6 mb-1"><?= e($client['company_name']) ?></h2>
                <div class="mb-2"><?= client_status_badge($client['status']) ?></div>
                <div class="small text-secondary"><?= e($client['contact_person']) ?></div>
            </div>
            <div class="card-body border-top">
                <dl class="detail-list mb-0">
                    <dt>Email</dt>
                    <dd>
                        <?php if ($client['email']): ?>
                            <a href="mailto:<?= e($client['email']) ?>"><?= e($client['email']) ?></a>
                        <?php else: ?><span class="text-secondary">Not provided</span><?php endif; ?>
                    </dd>

                    <dt>Phone</dt>
                    <dd>
                        <?php if ($client['phone']): ?>
                            <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $client['phone'])) ?>"><?= e($client['phone']) ?></a>
                        <?php else: ?><span class="text-secondary">Not provided</span><?php endif; ?>
                    </dd>

                    <dt>Address</dt>
                    <dd><?= e($client['address'] ?: 'Not provided') ?></dd>

                    <dt>Assigned to</dt>
                    <dd><?= e($client['owner_name'] ?: 'Unassigned') ?></dd>

                    <dt>Client since</dt>
                    <dd>
                        <?= e(nice_date($client['created_at'])) ?>
                        <?php if ($client['creator_name']): ?>
                            <span class="text-secondary">by <?= e($client['creator_name']) ?></span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
            <?php if ($client['notes']): ?>
                <div class="card-body border-top">
                    <dt class="small text-uppercase text-secondary fw-bold mb-2">Notes</dt>
                    <p class="mb-0" style="white-space: pre-line"><?= e($client['notes']) ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Deals -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-kanban me-2 text-primary"></i>Deals</span>
                <a href="<?= url('pipeline/form.php') ?>?client_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">Add</a>
            </div>
            <?php if (!$deals): ?>
                <?= empty_state('bi-kanban', 'No deals', 'Create a deal to start tracking revenue for this client.', 'deal_form.php?client_id=' . $id, 'Add deal') ?>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($deals as $deal): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between gap-2">
                                <div class="min-w-0">
                                    <a href="<?= url('pipeline/index.php') ?>?stage=<?= e($deal['stage']) ?>" class="fw-semibold small text-reset">
                                        <?= e($deal['deal_title']) ?>
                                    </a>
                                    <div class="small text-secondary">
                                        <?= stage_badge($deal['stage']) ?>
                                        <?php if ($deal['expected_close_date']): ?>
                                            <span class="ms-1">closes <?= e(nice_date($deal['expected_close_date'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="mono fw-semibold text-end"><?= e(money($deal['value'])) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="card-body border-top small">
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary">Total pipeline value</span>
                        <span class="mono fw-bold"><?= e(money($summary['total_value'])) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mt-1">
                        <span class="text-secondary">Deals</span>
                        <span class="mono fw-bold"><?= (int) $summary['deal_count'] ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ---------- Right column: tasks + history ---------- -->
    <div class="col-12 col-lg-8">
        <!-- Tasks -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-check2-square me-2 text-primary"></i>Tasks &amp; follow-ups</span>
                <a href="<?= url('tasks/form.php') ?>?client_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">Add task</a>
            </div>
            <?php if (!$tasks): ?>
                <?= empty_state('bi-check2-square', 'No tasks yet', 'Add a follow-up so nothing slips on this account.', 'task_form.php?client_id=' . $id, 'Add task') ?>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($tasks as $task):
                        $isLate = $task['status'] !== 'completed' && $task['due_date'] && $task['due_date'] < date('Y-m-d');
                    ?>
                        <li class="list-group-item d-flex gap-2 align-items-start py-3">
                            <form method="post" action="<?= url('tasks/action.php') ?>" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="<?= $task['status'] === 'completed' ? 'reopen' : 'complete' ?>">
                                <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                                <input type="hidden" name="return" value="client_view.php?id=<?= $id ?>">
                                <button type="submit" class="btn btn-sm <?= $task['status'] === 'completed' ? 'btn-light border' : 'btn-outline-success' ?>"
                                        title="<?= $task['status'] === 'completed' ? 'Reopen task' : 'Mark complete' ?>">
                                    <i class="bi <?= $task['status'] === 'completed' ? 'bi-arrow-counterclockwise' : 'bi-check-lg' ?>"></i>
                                </button>
                            </form>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold small <?= $task['status'] === 'completed' ? 'text-decoration-line-through text-secondary' : '' ?>">
                                    <?= e($task['title']) ?>
                                </div>
                                <?php if ($task['description']): ?>
                                    <div class="small text-secondary"><?= e($task['description']) ?></div>
                                <?php endif; ?>
                                <div class="small mt-1 d-flex flex-wrap gap-2 align-items-center">
                                    <?= priority_badge($task['priority']) ?>
                                    <?= task_status_badge($task['status']) ?>
                                    <span class="<?= $isLate ? 'text-danger fw-semibold' : 'text-secondary' ?>">
                                        <i class="bi bi-calendar-event me-1"></i><?= e(nice_date($task['due_date'])) ?>
                                    </span>
                                    <span class="text-secondary">· <?= e($task['owner_name'] ?: 'Unassigned') ?></span>
                                </div>
                            </div>
                            <a href="<?= url('tasks/form.php') ?>?id=<?= (int) $task['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Activity history -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2 text-primary"></i>Interaction history</span>
                <a href="<?= url('activities/form.php') ?>?client_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">Log activity</a>
            </div>
            <div class="card-body">
                <?php if (!$activities): ?>
                    <?= empty_state('bi-clock-history', 'No activity recorded', 'Log calls, emails, meetings and notes to build the relationship history.', 'activity_form.php?client_id=' . $id, 'Log activity') ?>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($activities as $activity):
                            [$typeLabel, $typeIcon] = activity_icon($activity['type']);
                        ?>
                            <div class="timeline-item">
                                <div class="timeline-dot <?= e($activity['type']) ?>"><i class="bi <?= e($typeIcon) ?>"></i></div>
                                <div class="d-flex justify-content-between gap-2">
                                    <div class="min-w-0">
                                        <div class="timeline-title"><?= e($activity['title']) ?></div>
                                        <?php if ($activity['details']): ?>
                                            <div class="small text-secondary" style="white-space: pre-line"><?= e($activity['details']) ?></div>
                                        <?php endif; ?>
                                        <div class="timeline-meta">
                                            <span class="badge text-bg-light border me-1"><?= e($typeLabel) ?></span>
                                            <?= e($activity['owner_name'] ?: 'Unknown') ?>
                                            · <span title="<?= e($activity['created_at']) ?>"><?= e(time_ago($activity['created_at'])) ?></span>
                                        </div>
                                    </div>
                                    <?php if (can_manage(['created_by' => $activity['created_by'], 'assigned_to' => 0])): ?>
                                        <a href="<?= url('activities/form.php') ?>?id=<?= (int) $activity['id'] ?>"
                                           class="btn btn-sm btn-outline-secondary flex-shrink-0" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
<?php if (count($activities) >= 15): ?>
                        <div class="text-center mt-3">
                            <a href="<?= url('activities/index.php') ?>?client_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-clock-history me-1"></i>View the full history
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../views/footer.php'; ?>

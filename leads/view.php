<?php
/**
 * Lead detail - contact info, linked deals, tasks and interaction history.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$id   = (int) ($_GET['id'] ?? 0);
$lead = $id > 0 ? lead_find($id) : null;

if (!$lead) {
    flash_error('That lead could not be found.', 'leads/index.php');
}

// See clients/view.php: without this, a staff user could read any lead's
// contact details by walking ?id=.
if ($lead && !can_view($lead)) {
    flash_error('You do not have permission to view that lead.', 'leads/index.php');
}

$deals      = lead_deals($id);
$tasks      = lead_tasks($id);
$activities = lead_activities($id, 15);
$canEdit    = can_manage($lead);

$pageTitle    = $lead['lead_name'];
$pageHeading  = $lead['lead_name'];
$pageSubtitle = ($lead['company'] ?: 'No company') . ' · ' . pretty($lead['lead_source']) . ' · ' . money($lead['estimated_value']);
$activeNav    = 'leads';
$breadcrumbs  = ['Dashboard' => 'dashboard.php', 'Leads' => 'leads/index.php', $lead['lead_name'] => null];
$pageActions = [
    ['label' => 'Create deal', 'href' => "pipeline/form.php?lead_id=$id", 'icon' => 'bi-kanban'],
    ['label' => 'Add task', 'href' => "tasks/form.php?lead_id=$id", 'variant' => 'outline-primary', 'icon' => 'bi-check2-square'],
    ['label' => 'Log activity', 'href' => "activities/form.php?lead_id=$id", 'variant' => 'outline-secondary', 'icon' => 'bi-clock-history'],
];
if ($canEdit) {
    $pageActions[] = ['label' => 'Edit', 'href' => "leads/form.php?id=$id", 'variant' => 'outline-secondary', 'icon' => 'bi-pencil'];
}

require __DIR__ . '/../views/header.php';
?>

<div class="row g-3">
    <div class="col-12 col-lg-4">
        <!-- Contact card -->
        <div class="card mb-3">
            <div class="card-body text-center">
                <span class="avatar avatar-lg mb-2" style="background:<?= e(avatar_colour($lead['lead_name'])) ?>">
                    <?= e(initials($lead['lead_name'])) ?>
                </span>
                <h2 class="h6 mb-1"><?= e($lead['lead_name']) ?></h2>
                <div class="small text-secondary mb-2"><?= e($lead['company'] ?: 'No company on file') ?></div>
                <?= lead_status_badge($lead['status']) ?>
            </div>
            <div class="card-body border-top">
                <dl class="detail-list mb-0">
                    <dt>Email</dt>
                    <dd>
                        <?php if ($lead['email']): ?>
                            <a href="mailto:<?= e($lead['email']) ?>"><?= e($lead['email']) ?></a>
                        <?php else: ?><span class="text-secondary">Not provided</span><?php endif; ?>
                    </dd>
                    <dt>Phone</dt>
                    <dd><?= e($lead['phone'] ?: 'Not provided') ?></dd>
                    <dt>Lead source</dt>
                    <dd><?= e(pretty($lead['lead_source'])) ?></dd>
                    <dt>Estimated value</dt>
                    <dd class="mono fw-semibold"><?= e(money($lead['estimated_value'])) ?></dd>
                    <dt>Assigned to</dt>
                    <dd><?= e($lead['owner_name'] ?: 'Unassigned') ?></dd>
                    <dt>Created</dt>
                    <dd>
                        <?= e(nice_date($lead['created_at'])) ?>
                        <?php if ($lead['creator_name']): ?>
                            <span class="text-secondary">by <?= e($lead['creator_name']) ?></span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
            <?php if ($lead['notes']): ?>
                <div class="card-body border-top">
                    <div class="small text-uppercase text-secondary fw-bold mb-2">Notes</div>
                    <p class="mb-0" style="white-space: pre-line"><?= e($lead['notes']) ?></p>
                </div>
            <?php endif; ?>

            <!-- Quick status change -->
            <?php if ($canEdit): ?>
                <div class="card-body border-top">
                    <form method="post" action="<?= url('leads/action.php') ?>" class="d-flex gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="id" value="<?= (int) $id ?>">
                        <input type="hidden" name="return" value="leads/view.php?id=<?= $id ?>">
                        <select name="status" class="form-select form-select-sm">
                            <?= select_options(lead_statuses(), $lead['status']) ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary text-nowrap">Update</button>
                    </form>
                    <div class="form-text mt-1">Quick status change without opening the edit form.</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Linked deals -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-kanban me-2 text-primary"></i>Deals</span>
                <a href="<?= url('pipeline/form.php') ?>?lead_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">Add</a>
            </div>
            <?php if (!$deals): ?>
                <?= empty_state('bi-kanban', 'Not converted yet', 'Turn this lead into a deal to start tracking it on the pipeline.', 'pipeline/form.php?lead_id=' . $id, 'Create deal') ?>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($deals as $deal): ?>
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            <div class="min-w-0">
                                <div class="fw-semibold small"><?= e($deal['deal_title']) ?></div>
                                <div class="small text-secondary">
                                    <?= stage_badge($deal['stage']) ?>
                                    <?php if ($deal['client_name']): ?>
                                        <span class="ms-1"><?= e($deal['client_name']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mono fw-semibold text-end"><?= e(money($deal['value'])) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Change history -->
        <?php
        $auditEntityType  = 'lead';
        $auditEntityId    = $id;
        $auditEntityLabel = $lead['lead_name'];
        require __DIR__ . '/../views/audit_history.php';
        ?>
    </div>

    <div class="col-12 col-lg-8">
        <!-- Tasks -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-check2-square me-2 text-primary"></i>Follow-ups</span>
                <a href="<?= url('tasks/form.php') ?>?lead_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">Add task</a>
            </div>
            <?php if (!$tasks): ?>
                <?= empty_state('bi-check2-square', 'No follow-ups', 'Schedule a call or send the proposal to keep momentum.', 'tasks/form.php?lead_id=' . $id, 'Add task') ?>
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
                                <input type="hidden" name="return" value="leads/view.php?id=<?= $id ?>">
                                <button type="submit" class="btn btn-sm <?= $task['status'] === 'completed' ? 'btn-light border' : 'btn-outline-success' ?>"
                                        title="<?= $task['status'] === 'completed' ? 'Reopen' : 'Mark complete' ?>">
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

        <!-- Activity -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2 text-primary"></i>Interaction history</span>
                <a href="<?= url('activities/form.php') ?>?lead_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">Log activity</a>
            </div>
            <div class="card-body">
                <?php if (!$activities): ?>
                    <?= empty_state('bi-clock-history', 'No activity recorded', 'Track every call, email and meeting with this lead.', 'activities/form.php?lead_id=' . $id, 'Log activity') ?>
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
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../views/footer.php'; ?>

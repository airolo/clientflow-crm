<?php
/**
 * Task create / edit form.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$task = $id > 0 ? task_find($id) : null;

if ($id > 0 && !$task) {
    flash_error('That task no longer exists.', 'tasks.php');
}

if ($task && !can_manage($task)) {
    flash_error('You can only edit tasks assigned to you.', 'tasks.php');
}

// Optional ?client_id= / ?lead_id= pre-links from the detail pages.
$presetClientId = (int) ($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
$presetLeadId   = (int) ($_GET['lead_id'] ?? $_POST['lead_id'] ?? 0);

$clients  = client_options();
$leads    = lead_options();
$users    = user_all(true);
$errors   = take_errors();
$old      = take_old();
$userId   = (int) current_user_id();
$isEdit   = $task !== null;
$sidebarPendingTasks = task_count_open_for_sidebar($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $data = [
        'title'       => post_str('title'),
        'description' => post_str('description'),
        'client_id'   => post_int('client_id') ?? 0,
        'lead_id'     => post_int('lead_id') ?? 0,
        'due_date'    => post_str('due_date'),
        'priority'    => post_str('priority', 'medium'),
        'status'      => post_str('status', 'pending'),
        'assigned_to' => post_int('assigned_to') ?? 0,
    ];

    if ($data['client_id'] && !client_find($data['client_id'])) {
        $data['client_id'] = 0;
    }
    if ($data['lead_id'] && !lead_find($data['lead_id'])) {
        $data['lead_id'] = 0;
    }
    if ($data['assigned_to'] && !user_find($data['assigned_to'])) {
        $data['assigned_to'] = 0;
    }

    $errors = task_validate($data);

    if (!$errors) {
        if ($isEdit) {
            task_update($id, $data);
            flash_success('Task "' . $data['title'] . '" was updated.', 'tasks.php');
        } else {
            $data['created_by'] = $userId;
            task_create($data);
            flash_success('Task "' . $data['title'] . '" was created.', 'tasks.php');
        }
    }

    redirect_with_errors(
        $isEdit ? 'task_form.php?id=' . $id : 'task_form.php',
        $errors,
        $_POST
    );
}

$record = $task ?? [
    'client_id'   => $presetClientId,
    'lead_id'     => $presetLeadId,
    'priority'    => 'medium',
    'status'      => 'pending',
    'due_date'    => date('Y-m-d', strtotime('+7 days')),
    'assigned_to' => $userId,
];

$pageTitle    = $isEdit ? 'Edit task' : 'Add task';
$pageHeading  = $isEdit ? 'Edit task' : 'Add task';
$pageSubtitle = $isEdit ? $task['title'] : 'Schedule a follow-up or reminder';
$activeNav    = 'tasks';
$breadcrumbs  = ['Dashboard' => 'index.php', 'Tasks' => 'tasks.php', $isEdit ? 'Edit' : 'Add' => null];
$pageActions  = '<a href="tasks.php" class="btn btn-light border"><i class="bi bi-arrow-left me-1"></i>Back to tasks</a>';

require __DIR__ . '/includes/header.php';
?>

<form method="post" action="task_form.php" class="row g-3" data-validate novalidate>
    <?= csrf_field() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">
    <?php endif; ?>

    <div class="col-12 col-lg-8">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-check2-square me-2 text-primary"></i>Task details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label for="title" class="form-label required">Title</label>
                        <input type="text" name="title" id="title" required maxlength="180"
                               class="form-control<?= is_invalid($errors, 'title') ?>"
                               value="<?= e(old_value($old, $record, 'title')) ?>"
                               placeholder="Send renewal quote to Northwind">
                        <?php if ($m = field_error($errors, 'title')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12">
                        <label for="description" class="form-label">Description</label>
                        <textarea name="description" id="description" rows="4" maxlength="2000" class="form-control"
                                  data-counter="descCounter"
                                  placeholder="Any context the assignee will need..."><?= e(old_value($old, $record, 'description')) ?></textarea>
                        <div class="form-text" id="descCounter">0 characters</div>
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="priority" class="form-label required">Priority</label>
                        <select name="priority" id="priority" class="form-select<?= is_invalid($errors, 'priority') ?>" required>
                            <?= select_options(task_priorities(), old_value($old, $record, 'priority', 'medium')) ?>
                        </select>
                        <?php if ($m = field_error($errors, 'priority')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="status" class="form-label required">Status</label>
                        <select name="status" id="status" class="form-select<?= is_invalid($errors, 'status') ?>" required>
                            <?= select_options(task_statuses(), old_value($old, $record, 'status', 'pending')) ?>
                        </select>
                        <?php if ($m = field_error($errors, 'status')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="due_date" class="form-label">Due date</label>
                        <input type="date" name="due_date" id="due_date"
                               class="form-control<?= is_invalid($errors, 'due_date') ?>"
                               value="<?= e(old_value($old, $record, 'due_date')) ?>">
                        <?php if ($m = field_error($errors, 'due_date')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-link-45deg me-2 text-primary"></i>Link &amp; ownership</div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="client_id" class="form-label">Related client</label>
                    <select name="client_id" id="client_id" class="form-select">
                        <option value="0">None</option>
                        <?php foreach ($clients as $clientId => $company): ?>
                            <option value="<?= (int) $clientId ?>"
                                <?= (string) old_value($old, $record, 'client_id') === (string) $clientId ? 'selected' : '' ?>>
                                <?= e($company) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="lead_id" class="form-label">Related lead</label>
                    <select name="lead_id" id="lead_id" class="form-select">
                        <option value="0">None</option>
                        <?php foreach ($leads as $leadId => $label): ?>
                            <option value="<?= (int) $leadId ?>"
                                <?= (string) old_value($old, $record, 'lead_id') === (string) $leadId ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Usually either a client or a lead, rarely both.</div>
                </div>

                <div class="mb-3">
                    <label for="assigned_to" class="form-label">Assigned to</label>
                    <select name="assigned_to" id="assigned_to" class="form-select">
                        <option value="0">Unassigned</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= (int) $user['id'] ?>"
                                <?= (string) old_value($old, $record, 'assigned_to') === (string) $user['id'] ? 'selected' : '' ?>>
                                <?= e($user['name']) ?> (<?= e(ucfirst($user['role'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($isEdit): ?>
                    <dl class="detail-list mb-0 pt-3 border-top">
                        <dt>Created by</dt>
                        <dd class="mb-2"><?= e($task['creator_name'] ?: 'Unknown') ?></dd>
                        <dt>Created</dt>
                        <dd class="mb-2"><?= e(nice_date($task['created_at'])) ?></dd>
                        <dt>Completed</dt>
                        <dd class="mb-0"><?= $task['completed_at'] ? e(nice_date($task['completed_at'])) : 'Not yet' ?></dd>
                    </dl>
                <?php endif; ?>

                <div class="d-grid gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Save changes' : 'Create task' ?>
                    </button>
                    <a href="tasks.php" class="btn btn-light border">Cancel</a>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
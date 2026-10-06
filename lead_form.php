<?php
/**
 * Lead create / edit form.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$lead = $id > 0 ? lead_find($id) : null;

if ($id > 0 && !$lead) {
    flash_error('That lead no longer exists.', 'leads.php');
}

if ($lead && !can_manage($lead)) {
    flash_error('You can only edit leads assigned to you.', 'leads.php');
}

$users   = user_all(true);
$errors  = take_errors();
$old     = take_old();
$userId  = (int) current_user_id();
$isEdit  = $lead !== null;
$sidebarPendingTasks = task_count_open_for_sidebar($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $data = [
        'lead_name'       => post_str('lead_name'),
        'company'         => post_str('company'),
        'email'           => post_str('email'),
        'phone'           => post_str('phone'),
        'lead_source'     => post_str('lead_source', 'website'),
        'status'          => post_str('status', 'new'),
        'estimated_value' => post_float('estimated_value'),
        'assigned_to'     => post_int('assigned_to') ?? 0,
        'notes'           => post_str('notes'),
    ];

    if ($data['assigned_to'] && !user_find($data['assigned_to'])) {
        $data['assigned_to'] = 0;
    }

    $errors = lead_validate($data);

    if (!$errors) {
        if ($isEdit) {
            lead_update($id, $data);
            flash_success('Lead "' . $data['lead_name'] . '" was updated.', 'lead_view.php?id=' . $id);
        } else {
            $data['created_by'] = $userId;
            $newId = lead_create($data);
            flash_success('Lead "' . $data['lead_name'] . '" was created.', 'lead_view.php?id=' . $newId);
        }
    }

    redirect_with_errors(
        $isEdit ? 'lead_form.php?id=' . $id : 'lead_form.php',
        $errors,
        $_POST
    );
}

$record = $lead ?? [];
$pageTitle   = $isEdit ? 'Edit lead' : 'Add lead';
$pageHeading = $isEdit ? 'Edit lead' : 'Add lead';
$pageSubtitle = $isEdit ? $lead['lead_name'] : 'Capture a new prospect';
$activeNav   = 'leads';
$breadcrumbs = $isEdit
    ? ['Dashboard' => 'index.php', 'Leads' => 'leads.php', $lead['lead_name'] => 'lead_view.php?id=' . $id, 'Edit' => null]
    : ['Dashboard' => 'index.php', 'Leads' => 'leads.php', 'Add' => null];
$pageActions = '<a href="' . ($isEdit ? 'lead_view.php?id=' . $id : 'leads.php') . '" class="btn btn-light border"><i class="bi bi-arrow-left me-1"></i>Back</a>';

require __DIR__ . '/includes/header.php';
?>

<form method="post" action="lead_form.php" class="row g-3" data-validate novalidate>
    <?= csrf_field() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">
    <?php endif; ?>

    <div class="col-12 col-lg-8">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-person-lines-fill me-2 text-primary"></i>Lead details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="lead_name" class="form-label required">Lead name</label>
                        <input type="text" name="lead_name" id="lead_name" required maxlength="120"
                               class="form-control<?= is_invalid($errors, 'lead_name') ?>"
                               value="<?= e(old_value($old, $record, 'lead_name')) ?>" placeholder="Jane Smith">
                        <?php if ($m = field_error($errors, 'lead_name')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="company" class="form-label">Company</label>
                        <input type="text" name="company" id="company" maxlength="150"
                               class="form-control" value="<?= e(old_value($old, $record, 'company')) ?>"
                               placeholder="Acme Ltd">
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" name="email" id="email" maxlength="150"
                               class="form-control<?= is_invalid($errors, 'email') ?>"
                               value="<?= e(old_value($old, $record, 'email')) ?>" placeholder="jane@acme.test">
                        <?php if ($m = field_error($errors, 'email')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="phone" class="form-label">Phone</label>
                        <input type="text" name="phone" id="phone" maxlength="40"
                               class="form-control" value="<?= e(old_value($old, $record, 'phone')) ?>"
                               placeholder="+44 161 000 0000">
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="lead_source" class="form-label required">Lead source</label>
                        <select name="lead_source" id="lead_source" class="form-select<?= is_invalid($errors, 'lead_source') ?>" required>
                            <?= select_options(lead_sources(), old_value($old, $record, 'lead_source', 'website')) ?>
                        </select>
                        <?php if ($m = field_error($errors, 'lead_source')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="estimated_value" class="form-label">Estimated deal value</label>
                        <div class="input-group">
                            <span class="input-group-text">£</span>
                            <input type="number" step="0.01" min="0" name="estimated_value" id="estimated_value"
                                   class="form-control<?= is_invalid($errors, 'estimated_value') ?>"
                                   value="<?= e(old_value($old, $record, 'estimated_value', '0')) ?>"
                                   placeholder="0.00">
                        </div>
                        <?php if ($m = field_error($errors, 'estimated_value')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea name="notes" id="notes" rows="5" maxlength="2000" class="form-control"
                                  data-counter="notesCounter"
                                  placeholder="What did they ask about? What is the timeline?"><?= e(old_value($old, $record, 'notes')) ?></textarea>
                        <div class="form-text" id="notesCounter">0 characters</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-sliders me-2 text-primary"></i>Pipeline settings</div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="status" class="form-label required">Status</label>
                    <select name="status" id="status" class="form-select<?= is_invalid($errors, 'status') ?>" required>
                        <?= select_options(lead_statuses(), old_value($old, $record, 'status', 'new')) ?>
                    </select>
                    <?php if ($m = field_error($errors, 'status')): ?>
                        <div class="invalid-feedback d-block"><?= e($m) ?></div>
                    <?php endif; ?>
                    <div class="form-text">A lead becomes a deal once you convert it on the pipeline.</div>
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
                    <dl class="detail-list mb-0 mt-3 pt-3 border-top">
                        <dt>Created</dt>
                        <dd class="mb-2">
                            <?= e(nice_date($lead['created_at'])) ?>
                            <?php if ($lead['creator_name']): ?>
                                <span class="text-secondary">by <?= e($lead['creator_name']) ?></span>
                            <?php endif; ?>
                        </dd>
                        <dt>Deals created</dt>
                        <dd class="mb-0"><?= (int) $lead['deal_count'] ?></dd>
                    </dl>
                <?php endif; ?>

                <div class="d-grid gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Save changes' : 'Create lead' ?>
                    </button>
                    <a href="<?= $isEdit ? 'lead_view.php?id=' . $id : 'leads.php' ?>" class="btn btn-light border">Cancel</a>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
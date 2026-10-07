<?php
/**
 * Deal create / edit form. Handles direct creation and lead conversion
 * (pre-filled from ?lead_id=N).
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$id     = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$deal   = $id > 0 ? deal_find($id) : null;

if ($id > 0 && !$deal) {
    flash_error('That deal no longer exists.', 'pipeline/index.php');
}

if ($deal && !can_manage($deal)) {
    flash_error('You are not allowed to modify this deal.', 'pipeline/index.php');
}

// When arriving from a lead, pre-select that lead and carry its value across.
$presetLeadId = (int) ($_GET['lead_id'] ?? $_POST['lead_id'] ?? 0);
$presetClientId = (int) ($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
$presetValue = '';
$presetTitle = '';
$presetStage = 'new_lead';

if (!$deal && $presetLeadId > 0) {
    $presetLead = lead_find($presetLeadId);
    if ($presetLead) {
        $presetTitle = $presetLead['company']
            ? $presetLead['company'] . ' - New Opportunity'
            : $presetLead['lead_name'] . ' - New Opportunity';
        $presetValue = (string) $presetLead['estimated_value'];
        $presetStage = 'contacted';
        $presetAssigned = (int) $presetLead['assigned_to'];
    }
}

$clients    = client_options();
$leads      = lead_options();
$users      = user_all(true);
$errors     = take_errors();
$old        = take_old();
$userId     = (int) current_user_id();
$isEdit     = $deal !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $data = [
        'deal_title'          => post_str('deal_title'),
        'client_id'           => post_int('client_id') ?? 0,
        'lead_id'             => post_int('lead_id') ?? 0,
        'value'               => post_float('value'),
        'stage'               => post_str('stage', 'new_lead'),
        'expected_close_date' => post_str('expected_close_date'),
        'assigned_to'         => post_int('assigned_to') ?? 0,
        'notes'               => post_str('notes'),
    ];

    // Confirm the foreign keys refer to real rows before saving.
    if ($data['client_id'] && !client_find($data['client_id'])) {
        $data['client_id'] = 0;
    }
    if ($data['lead_id'] && !lead_find($data['lead_id'])) {
        $data['lead_id'] = 0;
    }
    if ($data['assigned_to'] && !user_find($data['assigned_to'])) {
        $data['assigned_to'] = 0;
    }

    $errors = deal_validate($data);

    if (!$errors) {
        if ($isEdit) {
            deal_update($id, $data);
            flash_success('Deal "' . $data['deal_title'] . '" was updated.', 'pipeline/index.php');
        } else {
            $data['created_by'] = $userId;

            // Two writes: the deal, plus advancing the lead when converting.
            // A transaction keeps them all-or-nothing, so a failure cannot
            // leave a deal whose lead was never updated.
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $newId = deal_create($data);

                // Converting a lead moves it to "proposal" so the funnel stays honest.
                if ($data['lead_id'] && in_array($data['stage'], ['proposal', 'negotiation', 'won'], true)) {
                    $leadRow = lead_find($data['lead_id']);
                    if ($leadRow && in_array($leadRow['status'], ['new', 'contacted', 'qualified'], true)) {
                        lead_update_status($data['lead_id'], 'proposal');
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            flash_success('Deal "' . $data['deal_title'] . '" was added to the pipeline.', 'pipeline/index.php');
        }
    }

    redirect_with_errors(
        $isEdit ? 'deal_form.php?id=' . $id : 'pipeline/form.php',
        $errors,
        $_POST
    );
}

$record = $deal ?? [
    'deal_title'          => $presetTitle,
    'client_id'           => $presetClientId,
    'lead_id'             => $presetLeadId,
    'value'               => $presetValue,
    'stage'               => $presetStage,
    'assigned_to'         => $presetAssigned ?? $userId,
];

$pageTitle    = $isEdit ? 'Edit deal' : 'Add deal';
$pageHeading  = $isEdit ? 'Edit deal' : ($presetLeadId > 0 && !$isEdit ? 'Convert lead to deal' : 'Add deal');
$pageSubtitle = $isEdit ? $deal['deal_title'] : 'Track an opportunity through the pipeline';
$activeNav    = 'pipeline';
$breadcrumbs  = ['Dashboard' => 'dashboard.php', 'Pipeline' => 'pipeline/index.php', $isEdit ? 'Edit' : 'Add' => null];
$pageActions = [[
    'label' => 'Back to pipeline',
    'href' => 'pipeline/index.php',
    'variant' => 'light border',
    'icon' => 'bi-arrow-left',
]];

require __DIR__ . '/../views/header.php';
?>

<?php if (!$isEdit && $presetLeadId > 0): ?>
    <div class="alert alert-info d-flex align-items-start gap-2">
        <i class="bi bi-info-circle-fill mt-1"></i>
        <div>
            Converting lead <strong><?= e($presetTitle !== '' ? lead_find($presetLeadId)['lead_name'] : '') ?></strong>.
            The lead stays on record and its status moves to <em>proposal</em>.
        </div>
    </div>
<?php endif; ?>

<form method="post" action="<?= url('pipeline/form.php') ?>" class="row g-3" data-validate novalidate>
    <?= csrf_field() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">
    <?php endif; ?>

    <div class="col-12 col-lg-8">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-kanban me-2 text-primary"></i>Deal details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label for="deal_title" class="form-label required">Deal title</label>
                        <input type="text" name="deal_title" id="deal_title" required maxlength="180"
                               class="form-control<?= is_invalid($errors, 'deal_title') ?>"
                               value="<?= e(old_value($old, $record, 'deal_title')) ?>"
                               placeholder="Northwind - Platform Renewal">
                        <?php if ($m = field_error($errors, 'deal_title')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="client_id" class="form-label">Client</label>
                        <select name="client_id" id="client_id" class="form-select<?= is_invalid($errors, 'client_id') ?>">
                            <option value="0">Not linked to a client</option>
                            <?php foreach ($clients as $clientId => $company): ?>
                                <option value="<?= (int) $clientId ?>"
                                    <?= (string) old_value($old, $record, 'client_id') === (string) $clientId ? 'selected' : '' ?>>
                                    <?= e($company) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($m = field_error($errors, 'client_id')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="lead_id" class="form-label">Originating lead</label>
                        <select name="lead_id" id="lead_id" class="form-select">
                            <option value="0">Not linked to a lead</option>
                            <?php foreach ($leads as $leadId => $label): ?>
                                <option value="<?= (int) $leadId ?>"
                                    <?= (string) old_value($old, $record, 'lead_id') === (string) $leadId ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Link both when a deal came from a lead and now has a client account.</div>
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="value" class="form-label">Deal value</label>
                        <div class="input-group">
                            <span class="input-group-text">£</span>
                            <input type="number" step="0.01" min="0" name="value" id="value"
                                   class="form-control<?= is_invalid($errors, 'value') ?>"
                                   value="<?= e(old_value($old, $record, 'value', '0')) ?>" placeholder="0.00">
                        </div>
                        <?php if ($m = field_error($errors, 'value')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="stage" class="form-label required">Stage</label>
                        <select name="stage" id="stage" class="form-select<?= is_invalid($errors, 'stage') ?>" required>
                            <?= select_options(deal_stages(), old_value($old, $record, 'stage', 'new_lead')) ?>
                        </select>
                        <?php if ($m = field_error($errors, 'stage')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="expected_close_date" class="form-label">Expected close</label>
                        <input type="date" name="expected_close_date" id="expected_close_date"
                               class="form-control<?= is_invalid($errors, 'expected_close_date') ?>"
                               value="<?= e(old_value($old, $record, 'expected_close_date')) ?>">
                        <?php if ($m = field_error($errors, 'expected_close_date')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea name="notes" id="notes" rows="4" maxlength="2000" class="form-control"
                                  data-counter="notesCounter"
                                  placeholder="Key objections, commercial terms, next steps..."><?= e(old_value($old, $record, 'notes')) ?></textarea>
                        <div class="form-text" id="notesCounter">0 characters</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-sliders me-2 text-primary"></i>Ownership</div>
            <div class="card-body">
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
                        <dt>Stage</dt>
                        <dd class="mb-2"><?= stage_badge($deal['stage']) ?></dd>
                        <dt>Client</dt>
                        <dd class="mb-2"><?= e($deal['client_name'] ?: 'Not linked') ?></dd>
                        <dt>Lead</dt>
                        <dd class="mb-2"><?= e($deal['lead_name'] ?: 'Not linked') ?></dd>
                        <dt>Created</dt>
                        <dd class="mb-2">
                            <?= e(nice_date($deal['created_at'])) ?>
                            <?php if ($deal['creator_name']): ?>
                                <span class="text-secondary">by <?= e($deal['creator_name']) ?></span>
                            <?php endif; ?>
                        </dd>
                        <dt>Last updated</dt>
                        <dd class="mb-0"><?= e(nice_date($deal['updated_at'])) ?></dd>
                    </dl>
                <?php endif; ?>

                <div class="d-grid gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Save changes' : 'Add to pipeline' ?>
                    </button>
                    <a href="<?= url('pipeline/index.php') ?>" class="btn btn-light border">Cancel</a>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../views/footer.php'; ?>

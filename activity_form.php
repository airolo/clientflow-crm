<?php
/**
 * Activity create / edit form.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id       = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$activity = $id > 0 ? activity_find($id) : null;

if ($id > 0 && !$activity) {
    flash_error('That activity no longer exists.', 'activities.php');
}

if ($activity && !can_manage(['created_by' => $activity['created_by'], 'assigned_to' => 0])) {
    flash_error('You can only edit activities you created.', 'activities.php');
}

$presetClientId = (int) ($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
$presetLeadId   = (int) ($_GET['lead_id'] ?? $_POST['lead_id'] ?? 0);

// Where to send the user after a successful save. Whitelisted against the
// pages this form can legitimately return to, so it can never become an
// open redirect.
$returnUrl = 'activities.php';
if ($presetClientId > 0) {
    $returnUrl = 'client_view.php?id=' . $presetClientId;
} elseif ($presetLeadId > 0) {
    $returnUrl = 'lead_view.php?id=' . $presetLeadId;
}

$clients  = client_options();
$leads    = lead_options();
$errors   = take_errors();
$old      = take_old();
$isEdit   = $activity !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $data = [
        'client_id' => post_int('client_id') ?? 0,
        'lead_id'   => post_int('lead_id') ?? 0,
        'type'      => post_str('type', 'note'),
        'title'     => post_str('title'),
        'details'   => post_str('details'),
    ];

    if ($data['client_id'] && !client_find($data['client_id'])) {
        $data['client_id'] = 0;
    }
    if ($data['lead_id'] && !lead_find($data['lead_id'])) {
        $data['lead_id'] = 0;
    }

    $errors = activity_validate($data);

    if (!$errors) {
        if ($isEdit) {
            activity_update($id, $data);
            // $returnUrl is set above from the originating client/lead page.
            flash_success('Activity updated.', $returnUrl);
        } else {
            $data['created_by'] = (int) current_user_id();
            activity_create($data);
            flash_success('Activity logged.', $returnUrl);
        }
    }

    redirect_with_errors(
        $isEdit ? 'activity_form.php?id=' . $id : 'activity_form.php',
        $errors,
        $_POST
    );
}

$record = $activity ?? [
    'client_id' => $presetClientId,
    'lead_id'   => $presetLeadId,
    'type'      => 'note',
];

$scopeLabel = 'General activity log';
if ($presetClientId > 0) {
    $scopeClient = client_find($presetClientId);
    $scopeLabel = $scopeClient ? $scopeClient['company_name'] : '';
} elseif ($presetLeadId > 0) {
    $scopeLead = lead_find($presetLeadId);
    $scopeLabel = $scopeLead ? $scopeLead['lead_name'] : '';
}

$pageTitle    = $isEdit ? 'Edit activity' : 'Log activity';
$pageHeading  = $isEdit ? 'Edit activity' : 'Log activity';
$pageSubtitle = $isEdit ? $activity['title'] : ($scopeLabel !== '' ? 'For ' . $scopeLabel : 'Record a client interaction');
$activeNav    = 'activities';
$breadcrumbs  = ['Dashboard' => 'index.php', 'Activities' => 'activities.php', $isEdit ? 'Edit' : 'New' => null];
$pageActions = [[
    'label' => 'Back to activity',
    'href' => 'activities.php',
    'variant' => 'light border',
    'icon' => 'bi-arrow-left',
]];

require __DIR__ . '/includes/header.php';
?>

<form method="post" action="activity_form.php" class="row g-3" data-validate novalidate>
    <?= csrf_field() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">
    <?php endif; ?>

    <div class="col-12 col-lg-8">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i>Interaction</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label required d-block">What kind of interaction?</label>
                        <div class="row g-2">
                            <?php foreach (activity_types() as $typeOption):
                                [$label, $icon] = activity_icon($typeOption);
                                $checked = old_value($old, $record, 'type', 'note') === $typeOption;
                            ?>
                                <div class="col-6 col-md-3">
                                    <input type="radio" class="btn-check" name="type" id="type-<?= e($typeOption) ?>"
                                           value="<?= e($typeOption) ?>" <?= $checked ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2"
                                           for="type-<?= e($typeOption) ?>">
                                        <i class="bi <?= e($icon) ?>"></i><?= e($label) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($m = field_error($errors, 'type')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12">
                        <label for="title" class="form-label required">Summary</label>
                        <input type="text" name="title" id="title" required maxlength="180"
                               class="form-control<?= is_invalid($errors, 'title') ?>"
                               value="<?= e(old_value($old, $record, 'title')) ?>"
                               placeholder="Discussed renewal pricing and next steps">
                        <?php if ($m = field_error($errors, 'title')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12">
                        <label for="details" class="form-label">Details</label>
                        <textarea name="details" id="details" rows="6" maxlength="3000" class="form-control"
                                  data-counter="detailsCounter"
                                  placeholder="What was said, what was agreed, what happens next..."><?= e(old_value($old, $record, 'details')) ?></textarea>
                        <div class="form-text" id="detailsCounter">0 characters</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-link-45deg me-2 text-primary"></i>Link</div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="client_id" class="form-label">Client</label>
                    <select name="client_id" id="client_id" class="form-select<?= is_invalid($errors, 'client_id') ?>">
                        <option value="0">None</option>
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

                <div class="mb-3">
                    <label for="lead_id" class="form-label">Lead</label>
                    <select name="lead_id" id="lead_id" class="form-select">
                        <option value="0">None</option>
                        <?php foreach ($leads as $leadId => $label): ?>
                            <option value="<?= (int) $leadId ?>"
                                <?= (string) old_value($old, $record, 'lead_id') === (string) $leadId ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Every activity needs a client or a lead so the history stays useful.</div>
                </div>

                <?php if ($isEdit): ?>
                    <dl class="detail-list mb-0 pt-3 border-top">
                        <dt>Logged by</dt>
                        <dd class="mb-2"><?= e($activity['owner_name'] ?: 'Unknown') ?></dd>
                        <dt>Logged at</dt>
                        <dd class="mb-0"><?= e(date('d M Y, H:i', strtotime((string) $activity['created_at']))) ?></dd>
                    </dl>
                <?php endif; ?>

                <div class="d-grid gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Save changes' : 'Log activity' ?>
                    </button>
                    <a href="activities.php" class="btn btn-light border">Cancel</a>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * Client create / edit form. The same file handles both modes:
 *   client_form.php          -> add a new client
 *   client_form.php?id=3     -> edit client 3
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id      = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$client  = $id > 0 ? client_find($id) : null;

if ($id > 0 && !$client) {
    flash_error('That client no longer exists.', 'clients.php');
}

if ($client && !can_manage($client)) {
    flash_error('You can only edit clients assigned to you.', 'clients.php');
}

$users   = user_all(true);
$errors  = take_errors();
$old     = take_old();
$userId  = (int) current_user_id();
$isEdit  = $client !== null;
$sidebarPendingTasks = task_count_open_for_sidebar($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $data = [
        'company_name'   => post_str('company_name'),
        'contact_person' => post_str('contact_person'),
        'email'          => post_str('email'),
        'phone'          => post_str('phone'),
        'address'        => post_str('address'),
        'status'         => post_str('status', 'prospect'),
        'assigned_to'    => post_int('assigned_to') ?? 0,
        'notes'          => post_str('notes'),
    ];

    // The dropdown only lists real users, so verify the id before storing it.
    if ($data['assigned_to'] && !user_find($data['assigned_to'])) {
        $data['assigned_to'] = 0;
    }

    $errors = client_validate($data);

    if (!$errors) {
        if ($isEdit) {
            client_update($id, $data);
            flash_success('Client "' . $data['company_name'] . '" was updated.', 'client_view.php?id=' . $id);
        } else {
            $data['created_by'] = $userId;
            $newId = client_create($data);
            flash_success('Client "' . $data['company_name'] . '" was created.', 'client_view.php?id=' . $newId);
        }
    }

    redirect_with_errors(
        $isEdit ? 'client_form.php?id=' . $id : 'client_form.php',
        $errors,
        $_POST
    );
}

$record = $client ?? [];
$pageTitle   = $isEdit ? 'Edit client' : 'Add client';
$pageHeading = $isEdit ? 'Edit client' : 'Add client';
$pageSubtitle = $isEdit
    ? $client['company_name']
    : 'Create a new client account';
$activeNav   = 'clients';
$breadcrumbs = $isEdit
    ? ['Dashboard' => 'index.php', 'Clients' => 'clients.php', $client['company_name'] => 'client_view.php?id=' . $id, 'Edit' => null]
    : ['Dashboard' => 'index.php', 'Clients' => 'clients.php', 'Add' => null];
$pageActions = '<a href="' . ($isEdit ? 'client_view.php?id=' . $id : 'clients.php') . '" class="btn btn-light border"><i class="bi bi-arrow-left me-1"></i>Back</a>';

require __DIR__ . '/includes/header.php';
?>

<form method="post" action="client_form.php" class="row g-3" data-validate novalidate>
    <?= csrf_field() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">
    <?php endif; ?>

    <div class="col-12 col-lg-8">
        <!-- Company details -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-building me-2 text-primary"></i>Company details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="company_name" class="form-label required">Company name</label>
                        <input type="text" name="company_name" id="company_name" required maxlength="150"
                               class="form-control<?= is_invalid($errors, 'company_name') ?>"
                               value="<?= e(old_value($old, $record, 'company_name')) ?>"
                               placeholder="Acme Logistics Ltd">
                        <?php if ($m = field_error($errors, 'company_name')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="contact_person" class="form-label required">Contact person</label>
                        <input type="text" name="contact_person" id="contact_person" required maxlength="120"
                               class="form-control<?= is_invalid($errors, 'contact_person') ?>"
                               value="<?= e(old_value($old, $record, 'contact_person')) ?>"
                               placeholder="Jane Smith">
                        <?php if ($m = field_error($errors, 'contact_person')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" name="email" id="email" maxlength="150"
                               class="form-control<?= is_invalid($errors, 'email') ?>"
                               value="<?= e(old_value($old, $record, 'email')) ?>"
                               placeholder="jane@acme.test">
                        <?php if ($m = field_error($errors, 'email')): ?>
                            <div class="invalid-feedback d-block"><?= e($m) ?></div>
                        <?php else: ?>
                            <div class="form-text">Used for emailing activities from the client page.</div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="phone" class="form-label">Phone</label>
                        <input type="text" name="phone" id="phone" maxlength="40"
                               class="form-control" value="<?= e(old_value($old, $record, 'phone')) ?>"
                               placeholder="+44 161 000 0000">
                    </div>

                    <div class="col-12">
                        <label for="address" class="form-label">Address</label>
                        <input type="text" name="address" id="address" maxlength="255"
                               class="form-control" value="<?= e(old_value($old, $record, 'address')) ?>"
                               placeholder="12 Kingsway, Manchester M2 4WU">
                    </div>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-journal-text me-2 text-primary"></i>Notes</div>
            <div class="card-body">
                <label for="notes" class="form-label">Internal notes</label>
                <textarea name="notes" id="notes" rows="5" maxlength="2000" class="form-control"
                          data-counter="notesCounter"
                          placeholder="Anything useful about this account..."><?= e(old_value($old, $record, 'notes')) ?></textarea>
                <div class="form-text" id="notesCounter">0 characters</div>
            </div>
        </div>
    </div>

    <!-- Sidebar settings -->
    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-sliders me-2 text-primary"></i>Settings</div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="status" class="form-label required">Status</label>
                    <select name="status" id="status" class="form-select<?= is_invalid($errors, 'status') ?>" required>
                        <?= select_options(client_statuses(), old_value($old, $record, 'status', 'prospect')) ?>
                    </select>
                    <?php if ($m = field_error($errors, 'status')): ?>
                        <div class="invalid-feedback d-block"><?= e($m) ?></div>
                    <?php endif; ?>
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
                    <?php if (!$isEdit): ?>
                        <div class="form-text">Defaults to you on save.</div>
                    <?php endif; ?>
                </div>

                <?php if ($isEdit): ?>
                    <dl class="detail-list mb-0 mt-3 pt-3 border-top">
                        <dt>Created</dt>
                        <dd class="mb-2">
                            <?= e(nice_date($client['created_at'])) ?>
                            <?php if ($client['creator_name']): ?>
                                <span class="text-secondary">by <?= e($client['creator_name']) ?></span>
                            <?php endif; ?>
                        </dd>
                        <dt>Last updated</dt>
                        <dd class="mb-0"><?= e(nice_date($client['updated_at'])) ?></dd>
                    </dl>
                <?php endif; ?>

                <div class="d-grid gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Save changes' : 'Create client' ?>
                    </button>
                    <a href="<?= $isEdit ? 'client_view.php?id=' . $id : 'clients.php' ?>" class="btn btn-light border">Cancel</a>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
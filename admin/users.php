<?php
/**
 * User management (admin only).
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$search = trim((string) ($_GET['search'] ?? ''));
$role   = (string) ($_GET['role'] ?? '');
$page   = current_page_number();

$result = user_list([
    'search' => $search,
    'role'   => $role,
    'page'   => $page,
]);

$users  = $result['rows'];
$total  = $result['total'];
$offset = $result['offset'];

$errors  = take_errors();
$old     = take_old();
$userId  = (int) current_user_id();

// A failed create posts back here with the errors; reopen the dialog so the
// message is actually visible instead of silently doing nothing.
$reopenModal = $errors ? 'newUserModal' : '';

$pageTitle    = 'Users';
$pageHeading  = 'Users';
$pageSubtitle = $total . ' account' . ($total === 1 ? '' : 's') . ' with access';
$activeNav    = 'users';
$breadcrumbs  = ['Dashboard' => 'index.php', 'Users' => null];
$pageActions = [[
    'label' => 'Add user',
    'tag' => 'button',
    'icon' => 'bi-person-plus',
    'attrs' => ['data-bs-toggle' => 'modal', 'data-bs-target' => '#newUserModal'],
]];

require __DIR__ . '/../views/header.php';
?>

<?php render_filter_bar([
    'action' => 'admin/users.php',
    'fields' => [
        ['type' => 'search', 'name' => 'search', 'label' => 'Search', 'col' => 'col-12 col-md-6',
         'value' => $search, 'placeholder' => 'Name or email'],
        ['type' => 'select', 'name' => 'role', 'label' => 'Role', 'col' => 'col-6 col-md-3',
         'value' => $role, 'options' => ['admin' => 'Admin', 'staff' => 'Staff'], 'options_label' => 'All roles'],
    ],
    'actions_col' => 'col-6 col-md-3',
]); ?>

<div class="card">
    <div class="card-body p-0">
        <?php if (!$users): ?>
            <?= empty_state('bi-person-x', 'No matching users', 'Try a different search or clear the filters.', 'admin/users.php', 'Clear filters') ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <?php render_th('User'); ?>
                            <?php render_th('Role'); ?>
                            <?php render_th('Phone'); ?>
                            <?php render_th('Status'); ?>
                            <?php render_th('Workload'); ?>
                            <?php render_th('Joined'); ?>
                            <?php render_th('Actions', null, 'row-actions'); ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $workload = []; foreach (user_workload() as $row) { $workload[$row['id']] = $row; } ?>
                    <?php foreach ($users as $row):
                        $w = $workload[$row['id']] ?? null;
                    ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar avatar-sm" style="background:<?= e(avatar_colour($row['name'])) ?>">
                                        <?= e(initials($row['name'])) ?>
                                    </span>
                                    <div class="min-w-0">
                                        <div class="fw-semibold">
                                            <?= e($row['name']) ?>
                                            <?php if ((int) $row['id'] === $userId): ?>
                                                <span class="badge text-bg-light border">you</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small text-secondary"><?= e($row['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge text-bg-<?= $row['role'] === 'admin' ? 'primary' : 'secondary' ?>">
                                    <?= e(ucfirst($row['role'])) ?>
                                </span>
                            </td>
                            <td><span class="small text-secondary"><?= e($row['phone'] ?: '—') ?></span></td>
                            <td>
                                <?php if ((int) $row['is_active'] === 1): ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($w): ?>
                                    <span class="small text-secondary">
                                        <?= (int) $w['client_count'] ?> clients ·
                                        <?= (int) $w['lead_count'] ?> leads ·
                                        <?= (int) $w['open_tasks'] ?> tasks
                                    </span>
                                <?php else: ?>
                                    <span class="small text-secondary">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="small text-secondary"><?= e(nice_date($row['created_at'])) ?></span></td>
                            <td class="row-actions">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= url('admin/user_form.php') ?>?id=<?= (int) $row['id'] ?>" class="btn btn-outline-secondary"
                                       title="Edit" aria-label="Edit <?= e($row['name']) ?>"><i class="bi bi-pencil"></i></a>
                                    <?php if ((int) $row['id'] !== $userId): ?>
                                        <?php render_post_form_open([
                                            'action_url' => 'admin/user_action.php',
                                            'action' => 'delete',
                                            'id' => (int) $row['id'],
                                        ]); ?>
                                        <button type="submit" class="btn btn-outline-danger"
                                                data-confirm="Delete <?= e($row['name']) ?>? Their records stay but become unassigned."
                                                title="Delete" aria-label="Delete <?= e($row['name']) ?>"><i class="bi bi-trash"></i></button>
                                        <?php render_post_form_close(); ?>
                                    <?php endif; ?>
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

<?php render_table_footer($total, $offset, count($users)); ?>

<!-- ---------- Create user modal ---------- -->
<div class="modal fade" id="newUserModal" tabindex="-1" aria-labelledby="newUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= url('admin/user_action.php') ?>" data-validate novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div class="modal-header">
                <h5 class="modal-title" id="newUserModalLabel"><i class="bi bi-person-plus me-2"></i>New user</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger py-2">
                        <?php foreach ($errors as $message): ?>
                            <div><?= e($message) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label for="new_name" class="form-label required">Full name</label>
                    <input type="text" name="name" id="new_name" required maxlength="100"
                           class="form-control" value="<?= e(old_value($old, [], 'name')) ?>">
                </div>
                <div class="mb-3">
                    <label for="new_email" class="form-label required">Email</label>
                    <input type="email" name="email" id="new_email" required maxlength="150"
                           class="form-control" value="<?= e(old_value($old, [], 'email')) ?>">
                </div>
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <label for="new_password" class="form-label required">Password</label>
                        <input type="password" name="password" id="new_password" required minlength="8"
                               class="form-control" autocomplete="new-password">
                        <div class="form-text">Minimum 8 characters.</div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="new_role" class="form-label required">Role</label>
                        <select name="role" id="new_role" class="form-select" required>
                            <option value="staff" selected>Staff</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label for="new_phone" class="form-label">Phone</label>
                        <input type="text" name="phone" id="new_phone" maxlength="40"
                               class="form-control" value="<?= e(old_value($old, [], 'phone')) ?>">
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Create user</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../views/footer.php'; ?>

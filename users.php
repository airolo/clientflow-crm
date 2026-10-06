<?php
/**
 * User management (admin only).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$search   = trim((string) ($_GET['search'] ?? ''));
$role     = (string) ($_GET['role'] ?? '');
$page     = current_page_number();
$perPage  = ROWS_PER_PAGE;
$offset   = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE ? OR email LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like);
}
if ($role !== '' && in_array($role, ['admin', 'staff'], true)) {
    $where[] = 'role = ?';
    $params[] = $role;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare('SELECT COUNT(*) FROM users' . $whereSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = db()->prepare(
    'SELECT id, name, email, role, phone, is_active, created_at FROM users'
    . $whereSql . ' ORDER BY role = "admin" DESC, name ASC LIMIT '
    . $perPage . ' OFFSET ' . $offset
);
$stmt->execute($params);
$users = $stmt->fetchAll();

$errors  = take_errors();
$old     = take_old();
$userId  = (int) current_user_id();
$sidebarPendingTasks = task_count_open_for_sidebar($userId);

// A failed create posts back here with the errors; reopen the dialog so the
// message is actually visible instead of silently doing nothing.
$reopenModal = $errors ? 'newUserModal' : '';

$pageTitle    = 'Users';
$pageHeading  = 'Users';
$pageSubtitle = $total . ' account' . ($total === 1 ? '' : 's') . ' with access';
$activeNav    = 'users';
$breadcrumbs  = ['Dashboard' => 'index.php', 'Users' => null];
$pageActions  = '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newUserModal"><i class="bi bi-person-plus me-1"></i>Add user</button>';

require __DIR__ . '/includes/header.php';
?>

<div class="card mb-3">
    <div class="card-body filter-bar">
        <form method="get" action="users.php" class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
                <label for="search" class="form-label">Search</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="search" name="search" id="search" class="form-control"
                           value="<?= e($search) ?>" placeholder="Name or email">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <label for="role" class="form-label">Role</label>
                <select name="role" id="role" class="form-select">
                    <option value="">All roles</option>
                    <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="staff" <?= $role === 'staff' ? 'selected' : '' ?>>Staff</option>
                </select>
            </div>
            <div class="col-6 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="users.php" class="btn btn-light border" title="Clear"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (!$users): ?>
            <?= empty_state('bi-person-x', 'No matching users', 'Try a different search or clear the filters.', 'users.php', 'Clear filters') ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Workload</th>
                            <th>Joined</th>
                            <th class="row-actions">Actions</th>
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
                                    <a href="user_form.php?id=<?= (int) $row['id'] ?>" class="btn btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <?php if ((int) $row['id'] !== $userId): ?>
                                        <form method="post" action="user_action.php" class="m-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger"
                                                    data-confirm="Delete <?= e($row['name']) ?>? Their records stay but become unassigned."
                                                    title="Delete"><i class="bi bi-trash"></i></button>
                                        </form>
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

<?php if ($users): ?>
    <div class="table-footer">
        <div><?= result_summary($total, $offset, count($users)) ?></div>
        <?= render_pagination($total) ?>
    </div>
<?php endif; ?>

<!-- ---------- Create user modal ---------- -->
<div class="modal fade" id="newUserModal" tabindex="-1" aria-labelledby="newUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="user_action.php" data-validate novalidate>
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

<?php require __DIR__ . '/includes/footer.php'; ?>
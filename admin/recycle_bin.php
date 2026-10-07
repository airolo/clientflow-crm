<?php
/**
 * Recycle bin (admin only).
 *
 * Deleted clients, leads, deals, tasks and activities land here instead of
 * being destroyed. Because the delete only stamps deleted_at, restoring a
 * client brings its deals, tasks and activity history back with it.
 *
 * "Delete forever" is the one remaining hard DELETE, so it states the exact
 * number of child rows it destroys and refuses to run unless the admin types
 * the record's name.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$types   = soft_delete_types();
$type    = (string) ($_GET['type'] ?? 'client');
$valid   = array_key_exists($type, $types);
if (!$valid) {
    $type = 'client';
}

$search = trim((string) ($_GET['search'] ?? ''));
$page   = current_page_number();

$result = soft_delete_list($type, ['search' => $search, 'page' => $page]);
$rows   = $result['rows'];
$total  = $result['total'];
$offset = $result['offset'];
$counts = soft_delete_counts();
$binTotal = array_sum($counts);

$meta = $types[$type];

$pageTitle    = 'Recycle bin';
$pageHeading  = 'Recycle bin';
$pageSubtitle = $binTotal === 0
    ? 'Nothing has been deleted'
    : $binTotal . ' deleted ' . ($binTotal === 1 ? 'record' : 'records') . ' that can be restored';
$activeNav    = 'users';
$breadcrumbs  = ['Dashboard' => 'dashboard.php', 'Recycle bin' => null];

require __DIR__ . '/../views/header.php';
?>

<div class="alert alert-info d-flex gap-2" role="alert">
    <i class="bi bi-info-circle-fill flex-shrink-0"></i>
    <div>
        Deleting hides a record and keeps it here; its deals, tasks and history are untouched and
        come back with it. <strong>Delete forever</strong> is the only action that really removes
        data, and it cannot be undone.
    </div>
</div>

<?php render_filter_bar([
    'action' => 'admin/recycle_bin.php',
    'fields' => [
        ['type' => 'hidden', 'name' => 'type', 'value' => $type],
        ['type' => 'search', 'name' => 'search', 'label' => 'Search', 'col' => 'col-12 col-md-6',
         'value' => $search, 'placeholder' => 'Name or the person who deleted it'],
    ],
    'actions_col' => 'col-12 col-md-6',
]); ?>

<ul class="nav nav-pills mb-3">
    <?php foreach ($types as $key => $info):
        $n = $counts[$key] ?? 0;
    ?>
        <li class="nav-item">
            <a class="nav-link<?= $key === $type ? ' active' : '' ?>"
               href="<?= e(url_with(['type' => $key, 'page' => 1, 'search' => ''])) ?>">
                <i class="bi <?= e($info['icon']) ?> me-1"></i><?= e($info['title']) ?>
                <span class="badge text-bg-<?= $n > 0 ? 'secondary' : 'light' ?> ms-1"><?= $n ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="card">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <?= empty_state(
                $meta['icon'],
                'Nothing deleted here',
                $binTotal === 0
                    ? 'Deleted records will appear here and can be restored.'
                    : 'No deleted ' . strtolower($meta['title']) . ' match. Try another tab or clear the search.',
                'admin/recycle_bin.php',
                'Clear search'
            ) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <?php render_th($meta['summary']); ?>
                            <?php render_th('Deleted'); ?>
                            <?php render_th('By'); ?>
                            <?php render_th('Actions', null, 'row-actions'); ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $label = (string) $row[$meta['label']];
                        $children = soft_delete_child_count($type, (int) $row['id']);
                    ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($label) ?></div>
                                <div class="small text-secondary">
                                    <?php if ($type === 'client' && !empty($row['contact_person'])): ?>
                                        <?= e($row['contact_person']) ?>
                                        <?php if ($children > 0): ?>
                                            · <?= $children ?> attached <?= $children === 1 ? 'record' : 'records' ?>
                                        <?php endif; ?>
                                    <?php elseif ($children > 0): ?>
                                        <?= $children ?> attached <?= $children === 1 ? 'record' : 'records' ?>
                                    <?php else: ?>
                                        No linked records
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="small text-secondary"><?= e(date('j M Y, H:i', strtotime((string) $row['deleted_at']))) ?></span>
                            </td>
                            <td>
                                <span class="small text-secondary"><?= e($row['deleted_by_name'] ?: 'Unknown') ?></span>
                            </td>
                            <td class="row-actions">
                                <div class="btn-group btn-group-sm">
                                    <?php render_post_form_open([
                                        'action_url' => 'admin/recycle_action.php',
                                        'action' => 'restore',
                                        'type' => $type,
                                        'id' => (int) $row['id'],
                                    ]); ?>
                                    <button type="submit" class="btn btn-outline-success"
                                            title="Restore" aria-label="Restore <?= e($label) ?>">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                    <?php render_post_form_close(); ?>

                                    <button type="button" class="btn btn-outline-danger"
                                            data-bs-toggle="modal" data-bs-target="#purgeModal"
                                            data-type="<?= e($type) ?>"
                                            data-id="<?= (int) $row['id'] ?>"
                                            data-label="<?= e($label) ?>"
                                            data-children="<?= $children ?>"
                                            title="Delete forever" aria-label="Delete <?= e($label) ?> forever">
                                        <i class="bi bi-x-octagon"></i>
                                    </button>
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

<?php render_table_footer($total, $offset, count($rows)); ?>

<!-- ---------- Delete forever ---------- -->
<div class="modal fade" id="purgeModal" tabindex="-1" aria-labelledby="purgeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= url('admin/recycle_action.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="purge">
            <input type="hidden" name="type" id="purgeType">
            <input type="hidden" name="id" id="purgeId">

            <div class="modal-header">
                <h5 class="modal-title" id="purgeModalLabel">
                    <i class="bi bi-exclamation-octagon-fill text-danger me-2"></i>Delete forever
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="mb-2">
                    This permanently removes <strong id="purgeLabel">this record</strong>. There is no
                    recycle bin for this action and no way to undo it.
                </p>
                <p class="text-danger small mb-3" id="purgeChildren"></p>

                <label for="purgeConfirm" class="form-label">
                    Type <code id="purgeExpected">the name</code> to confirm
                </label>
                <input type="text" id="purgeConfirm" name="confirm" class="form-control"
                       autocomplete="off" data-validate-required-for="purgeSubmit">
                <div class="form-text">The button stays disabled until this matches exactly.</div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger" id="purgeSubmit" disabled>
                    <i class="bi bi-trash me-1"></i>Delete forever
                </button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../views/footer.php'; ?>

<?php
/**
 * Audit log (admin only).
 *
 * Who changed what, field by field, in this workspace. Read-only on purpose:
 * there is no edit, no delete and no clear-history button anywhere on this page,
 * and adding one would defeat the point of keeping it.
 *
 * Every query here is scoped to the session's tenant. The log holds what people
 * in one business did to that business's data, so a filter parameter must never
 * be able to reach another workspace's rows. audit_list() builds the tenant
 * predicate itself rather than accepting one, which is what keeps that true.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$action = (string) ($_GET['action'] ?? '');
$type   = (string) ($_GET['entity_type'] ?? '');
$search = trim((string) ($_GET['search'] ?? ''));
$from   = (string) ($_GET['date_from'] ?? '');
$to     = (string) ($_GET['date_to'] ?? '');

$result  = audit_list([
    'action'     => $action,
    'entity_type' => $type,
    'search'     => $search,
    'date_from'  => $from,
    'date_to'    => $to,
    'page'       => current_page_number(),
    'per_page'   => 25,
]);
$rows     = $result['rows'];
$total    = $result['total'];
$perPage  = 25;
$knownTypes = audit_entity_types();

$hasFilters = $action !== '' || $type !== '' || $search !== '' || $from !== '' || $to !== '';

$pageTitle   = 'Audit log';
$breadcrumbs = ['Dashboard' => 'dashboard.php', 'Admin' => null, 'Audit log' => null];

require __DIR__ . '/../views/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1">Audit log</h1>
        <p class="text-muted mb-0">
            Every create, edit, delete and sign-in in this workspace, with the
            values before and after. <?= number_format(audit_count()) ?> entries,
            kept permanently.
        </p>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(url('admin/audit_log.php')) ?>" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label for="fSearch" class="form-label">Search</label>
                <input type="search" id="fSearch" name="search" class="form-control form-control-sm"
                       value="<?= e($search) ?>" placeholder="Record, person or type">
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="fAction" class="form-label">Action</label>
                <select id="fAction" name="action" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach (audit_actions() as $a): ?>
                        <option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>>
                            <?= e(ucfirst($a)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="fType" class="form-label">Record type</label>
                <select id="fType" name="entity_type" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($knownTypes as $t): ?>
                        <option value="<?= e($t) ?>" <?= $type === $t ? 'selected' : '' ?>>
                            <?= e(ucfirst($t)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="fFrom" class="form-label">From</label>
                <input type="date" id="fFrom" name="date_from" class="form-control form-control-sm"
                       value="<?= e($from) ?>">
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="fTo" class="form-label">To</label>
                <input type="date" id="fTo" name="date_to" class="form-control form-control-sm"
                       value="<?= e($to) ?>">
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                <?php if ($hasFilters): ?>
                    <a href="<?= e(url('admin/audit_log.php')) ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($rows === []): ?>
    <?php if ($hasFilters): ?>
        <?= empty_state(
            'bi-clock-history',
            'No matching entries',
            'Try a different search or clear the filters.',
            'admin/audit_log.php',
            'Clear filters'
        ) ?>
    <?php else: ?>
        <?= empty_state(
            'bi-clock-history',
            'No activity recorded yet',
            'Entries appear here as records are created and changed.'
        ) ?>
    <?php endif; ?>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Who</th>
                        <th scope="col">Action</th>
                        <th scope="col">Record</th>
                        <th scope="col">Change</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="text-nowrap">
                            <?= e(nice_date($row['created_at'])) ?>
                            <div class="small text-muted"><?= e(time_ago($row['created_at'])) ?></div>
                        </td>
                        <td class="text-nowrap">
                            <?= e($row['user_name'] ?? 'System') ?>
                            <?php if ($row['ip']): ?>
                                <div class="small text-muted"><?= e($row['ip']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <span class="badge text-bg-<?= e(audit_action_badge($row['action'])) ?>">
                                <?= e(ucfirst($row['action'])) ?>
                            </span>
                            <div class="small text-muted"><?= e($row['entity_type']) ?></div>
                        </td>
                        <td><?= e($row['entity_label'] ?? ('#' . $row['entity_id'])) ?></td>
                        <td>
                            <?php if (empty($row['changes'])): ?>
                                <span class="text-muted">&mdash;</span>
                            <?php else: ?>
                                <?php foreach ($row['changes'] as $change): ?>
                                    <div class="small">
                                        <span class="fw-semibold"><?= e((string) ($change['label'] ?? $change['field'])) ?>:</span>
                                        <?php if ($change['from'] === null || $change['from'] === ''): ?>
                                            <span class="text-muted">(empty)</span>
                                        <?php else: ?>
                                            <span class="text-decoration-line-through text-muted"><?= e((string) $change['from']) ?></span>
                                        <?php endif; ?>
                                        <span>&rarr;</span>
                                        <?php if ($change['to'] === null || $change['to'] === ''): ?>
                                            <span class="text-muted">(empty)</span>
                                        <?php else: ?>
                                            <?= e((string) $change['to']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?= render_pagination($total, $perPage) ?>
<?php endif; ?>

<?php require __DIR__ . '/../views/footer.php'; ?>
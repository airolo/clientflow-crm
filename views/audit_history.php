<?php
/**
 * Per-record change history card.
 *
 * Required, not included, so it shares the caller's scope - the same pattern as
 * views\alerts.php and views\sidebar.php. The caller sets:
 *
 *   $auditEntityType  'client', 'lead', 'deal', 'task', 'activity'
 *   $auditEntityId    the record's id
 *
 * and optionally $auditHistoryLimit (default 10).
 *
 * Every query is already tenant-scoped in AuditModel, so this card cannot show
 * another workspace's history even if the caller is handed a bad id. That is why
 * the id is trusted here rather than re-checked: the guard belongs in the model,
 * not in each view that happens to render a history.
 */

/** @var string $auditEntityType */
/** @var int    $auditEntityId */

$auditLimit     = $auditHistoryLimit ?? 10;
$auditEntries   = audit_for_entity($auditEntityType, (int) $auditEntityId, (int) $auditLimit);
// Fetch one more than we render, so the "more" link only appears when there is
// genuinely more to see.
$auditHasMore   = count($auditEntries) > $auditLimit;
$auditEntries   = array_slice($auditEntries, 0, $auditLimit);
$auditSearchUrl = 'admin/audit_log.php?entity_type=' . urlencode($auditEntityType)
                . '&search=' . urlencode((string) ($auditEntityLabel ?? ''));
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-journal-text me-2 text-primary"></i>Change history</span>
        <?php if ($auditEntries): ?>
            <a href="<?= e(url($auditSearchUrl)) ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-journal-text me-1"></i>Audit log
            </a>
        <?php endif; ?>
    </div>
    <?php if (!$auditEntries): ?>
        <?= empty_state(
            'bi-journal-text',
            'No changes recorded',
            'Every edit to this record will be listed here, with the value before and after.'
        ) ?>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($auditEntries as $entry): ?>
                <li class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                        <span class="badge text-bg-<?= e(audit_action_badge($entry['action'])) ?>">
                            <?= e(ucfirst($entry['action'])) ?>
                        </span>
                        <span class="small text-secondary text-nowrap"
                              title="<?= e($entry['created_at']) ?>">
                            <?= e(time_ago($entry['created_at'])) ?>
                        </span>
                    </div>
                    <div class="small">
                        <?= e($entry['user_name'] ?? 'System') ?>
                        <?php if (empty($entry['changes'])): ?>
                            <span class="text-secondary">- no field detail</span>
                        <?php endif; ?>
                    </div>
                    <?php foreach ($entry['changes'] as $change): ?>
                        <div class="small mt-1">
                            <span class="fw-semibold"><?= e((string) ($change['label'] ?? $change['field'])) ?>:</span>
                            <?php if ($change['from'] === null || $change['from'] === ''): ?>
                                <span class="text-secondary">(empty)</span>
                            <?php else: ?>
                                <span class="text-decoration-line-through text-secondary"><?= e((string) $change['from']) ?></span>
                            <?php endif; ?>
                            <span class="text-secondary">&rarr;</span>
                            <?php if ($change['to'] === null || $change['to'] === ''): ?>
                                <span class="text-secondary">(empty)</span>
                            <?php else: ?>
                                <?= e((string) $change['to']) ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($auditHasMore): ?>
            <div class="card-body border-top text-center">
                <a href="<?= e(url($auditSearchUrl)) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-clock-history me-1"></i>See all changes in the audit log
                </a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
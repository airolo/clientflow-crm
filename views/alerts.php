<?php
/**
 * Reusable flash-message block. Safe to call on any page.
 */
$flashes = take_flashes();
if ($flashes):
    $icons = [
        'success' => 'bi-check-circle-fill',
        'danger'  => 'bi-exclamation-triangle-fill',
        'warning' => 'bi-exclamation-circle-fill',
        'info'    => 'bi-info-circle-fill',
    ];
?>
<div class="flash-stack">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e($flash['type'] ?? 'info') ?> alert-dismissible fade show d-flex align-items-start gap-2"
             role="alert">
            <i class="bi <?= e($icons[$flash['type']] ?? 'bi-info-circle-fill') ?> mt-1"></i>
            <div class="flex-grow-1"><?= e($flash['message']) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
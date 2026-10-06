<?php
/**
 * Recycle bin actions (admin only): restore, or delete forever.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/recycle_bin.php');
}

verify_csrf();

$action = post_str('action');
$type   = post_str('type');
$id     = (int) ($_POST['id'] ?? 0);

// Keep the admin on the tab they were working in.
$return = 'admin/recycle_bin.php?type=' . urlencode($type);

if (!soft_delete_type($type)) {
    flash_error('Unknown record type.', 'admin/recycle_bin.php');
}

$row = $id > 0 ? soft_delete_find($type, $id) : null;

if (!$row) {
    flash_error('That record is no longer in the recycle bin.', $return);
}

$label = (string) $row[soft_delete_type($type)['label']];

if ($action === 'restore') {
    if (soft_delete_restore($type, $id)) {
        $children = soft_delete_child_count($type, $id);
        flash_success(
            $label . ' was restored'
            . ($children > 0 ? ', along with ' . $children . ' linked ' . ($children === 1 ? 'record' : 'records') : '')
            . '.',
            $return
        );
    } else {
        flash_error('That record could not be restored.', $return);
    }
}

if ($action === 'purge') {
    // The only irreversible action in the app, so it is confirmed by name both
    // in the browser and again here. Never trust the client-side check alone.
    $typed = trim((string) ($_POST['confirm'] ?? ''));
    $children = soft_delete_child_count($type, $id);

    if ($typed !== $label) {
        flash_error('The name did not match, so nothing was deleted.', $return);
    }

    if (soft_delete_purge($type, $id)) {
        flash_success(
            $label . ' was deleted forever'
            . ($children > 0 ? ', along with ' . $children . ' linked ' . ($children === 1 ? 'record' : 'records') : '')
            . '.',
            $return
        );
    } else {
        flash_error('That record could not be deleted.', $return);
    }
}

flash_error('Unknown action requested.', $return);

<?php
/**
 * Deal POST actions: move stage, delete.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pipeline/index.php');
}

verify_csrf();

$action = post_str('action');
$id     = (int) ($_POST['id'] ?? 0);
$return = post_str('return', 'pipeline/index.php');

// Only allow our own pipeline page as a return target.
if (!preg_match('#^pipeline\.php(\?[a-z_]+=[0-9]*)?$#', $return)) {
    $return = 'pipeline/index.php';
}

$deal = $id > 0 ? deal_find($id) : null;

if (!$deal) {
    flash_error('That deal no longer exists.', $return);
}

if (!can_manage($deal)) {
    flash_error('You are not allowed to modify this deal.', $return);
}

if ($action === 'move') {
    $stage = post_str('stage');
    if (!is_valid_option($stage, deal_stages())) {
        flash_error('That is not a valid pipeline stage.', $return);
    }

    deal_move($id, $stage);
    flash_success('"' . $deal['deal_title'] . '" moved to ' . pretty(str_replace('_', ' ', $stage)) . '.', $return);
}

if ($action === 'delete') {
    deal_delete($id);
    flash_success('Deal "' . $deal['deal_title'] . '" moved to the recycle bin.', 'pipeline/index.php');
}

flash_error('Unknown action requested.', $return);
<?php
/**
 * Lead POST actions: delete, quick status change.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('leads/index.php');
}

verify_csrf();

$action = post_str('action');
$id     = (int) ($_POST['id'] ?? 0);
$return = post_str('return', 'leads/index.php');

// Whitelist the return path so it cannot be used as an open redirect.
$allowedReturns = ['leads/index.php', 'index.php'];
if ($id > 0) {
    $allowedReturns[] = 'lead_view.php?id=' . $id;
}
if (!in_array($return, $allowedReturns, true)) {
    $return = 'leads/index.php';
}

$lead = $id > 0 ? lead_find($id) : null;

if (!$lead) {
    flash_error('That lead no longer exists.', $return);
}

if (!can_manage($lead)) {
    flash_error('You are not allowed to modify this lead.', $return);
}

if ($action === 'delete') {
    lead_delete($id);
    flash_success('Lead "' . $lead['lead_name'] . '" moved to the recycle bin.', 'leads/index.php');
}

if ($action === 'status') {
    $status = post_str('status');
    if (!is_valid_option($status, lead_statuses())) {
        flash_error('That is not a valid lead status.', $return);
    }
    lead_update_status($id, $status);
    flash_success('Lead status changed to ' . pretty($status) . '.', $return);
}

flash_error('Unknown action requested.', $return);
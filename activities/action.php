<?php
/**
 * Activity POST actions: delete.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('activities/index.php');
}

verify_csrf();

$action = post_str('action');
$id     = (int) ($_POST['id'] ?? 0);
$return = post_str('return', 'activities/index.php');

$allowedReturns = ['activities/index.php', 'index.php'];
if ($id > 0) {
    $activityRow = activity_find($id);
    if ($activityRow && $activityRow['client_id']) {
        $allowedReturns[] = 'client_view.php?id=' . (int) $activityRow['client_id'];
    }
    if ($activityRow && $activityRow['lead_id']) {
        $allowedReturns[] = 'lead_view.php?id=' . (int) $activityRow['lead_id'];
    }
}
if (!in_array($return, $allowedReturns, true)) {
    $return = 'activities/index.php';
}

$activity = $id > 0 ? activity_find($id) : null;

if (!$activity) {
    flash_error('That activity no longer exists.', $return);
}

if (!can_manage(['created_by' => $activity['created_by'], 'assigned_to' => 0])) {
    flash_error('You can only delete activities you created.', $return);
}

if ($action === 'delete') {
    activity_delete($id);
    flash_success('Activity moved to the recycle bin.', $return);
}

flash_error('Unknown action requested.', $return);
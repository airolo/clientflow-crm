<?php
/**
 * Task POST actions: complete, reopen, delete.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('tasks.php');
}

verify_csrf();

$action = post_str('action');
$id     = (int) ($_POST['id'] ?? 0);
$return = post_str('return', 'tasks.php');

// Only our own pages are valid return targets. The client and lead detail
// pages are derived from the task's own links, not from the task id - using
// $id here would redirect to an unrelated record.
$allowedReturns = ['tasks.php', 'index.php'];
if ($taskRow = ($id > 0 ? task_find($id) : null)) {
    if ($taskRow['client_id']) {
        $allowedReturns[] = 'client_view.php?id=' . (int) $taskRow['client_id'];
    }
    if ($taskRow['lead_id']) {
        $allowedReturns[] = 'lead_view.php?id=' . (int) $taskRow['lead_id'];
    }
}
if (!in_array($return, $allowedReturns, true)) {
    $return = 'tasks.php';
}

$task = $id > 0 ? task_find($id) : null;

if (!$task) {
    flash_error('That task no longer exists.', $return);
}

if (!can_manage($task)) {
    flash_error('You are not allowed to modify this task.', $return);
}

if ($action === 'complete') {
    task_set_status($id, 'completed');
    flash_success('Task marked as completed.', $return);
}

if ($action === 'reopen') {
    task_set_status($id, 'pending');
    flash_success('Task reopened.', $return);
}

if ($action === 'delete') {
    task_delete($id);
    flash_success('Task "' . $task['title'] . '" was deleted.', 'tasks.php');
}

flash_error('Unknown action requested.', $return);
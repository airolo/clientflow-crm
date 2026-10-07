<?php
/**
 * Client POST actions (currently: delete).
 * Kept separate from the list page so the HTML form posts have somewhere to land.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('clients/index.php');
}

verify_csrf();

$action = post_str('action');
$id     = (int) ($_POST['id'] ?? 0);
$return  = post_str('return', 'clients/index.php');

// Only allow redirects to our own pages.
if (!in_array($return, ['clients/index.php', 'dashboard.php'], true)) {
    $return = 'clients/index.php';
}

$client = $id > 0 ? client_find($id) : null;

if (!$client) {
    flash_error('That client no longer exists.', $return);
}

if (!can_manage($client)) {
    flash_error('You are not allowed to modify this client.', $return);
}

if ($action === 'delete') {
    client_delete($id);
    flash_success('Client "' . $client['company_name'] . '" moved to the recycle bin.', $return);
}

flash_error('Unknown action requested.', $return);
<?php
/**
 * Bootstrap - single entry point required by every page.
 * Loads config, database, helpers, auth and starts the session.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/list_page.php';
require_once __DIR__ . '/auth.php';

/**
 * Uncaught exceptions must never reach the browser.
 *
 * PDO runs in exception mode, so any query failure would otherwise render a
 * stack trace containing absolute file paths, the failing SQL and source
 * context. The detail goes to the error log instead; the user gets a plain
 * page they can act on.
 */
set_exception_handler(function (Throwable $e): void {
    error_log(sprintf(
        'ClientFlow uncaught %s in %s:%d - %s',
        get_class($e),
        $e->getFile(),
        $e->getLine(),
        $e->getMessage()
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Something went wrong</title>'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">'
        . '</head><body class="bg-light"><div class="container py-5" style="max-width:40rem">'
        . '<div class="card shadow-sm"><div class="card-body p-4">'
        . '<h1 class="h4 mb-3">Something went wrong</h1>'
        . '<p class="text-secondary">The request could not be completed and nothing was saved.</p>'
        . '<p class="small text-secondary">The details have been written to the Apache error log '
        . '(<code>logs/error.log</code> in your XAMPP folder).</p>'
        . '<a href="index.php" class="btn btn-primary">Back to the dashboard</a>'
        . '</div></div></div></body></html>';
    exit;
});

// Models hold every database query, so pages stay presentation-only.
require_once __DIR__ . '/../models/ListQuery.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../models/ClientModel.php';
require_once __DIR__ . '/../models/LeadModel.php';
require_once __DIR__ . '/../models/DealModel.php';
require_once __DIR__ . '/../models/TaskModel.php';
require_once __DIR__ . '/../models/ActivityModel.php';
require_once __DIR__ . '/../models/ReportModel.php';

start_secure_session();

// Default page variables so templates never hit "undefined variable" notices.
$pageTitle = $pageTitle ?? APP_NAME;
$pageHeading = $pageHeading ?? '';
$activeNav = $activeNav ?? '';
$breadcrumbs = $breadcrumbs ?? [];
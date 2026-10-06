<?php
/**
 * ClientFlow CRM - Application configuration
 * -------------------------------------------------------------
 * Change the database credentials below if your XAMPP setup differs.
 */

declare(strict_types=1);

// --- Database (XAMPP defaults) ---
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'clientflow_crm');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

// --- Application ---
define('APP_NAME', 'ClientFlow CRM');
define('APP_SHORT', 'ClientFlow');
define('APP_TIMEZONE', 'Europe/London');
define('ROWS_PER_PAGE', 10);

/**
 * URL path the project is served from, without a trailing slash.
 *
 * Works whether the folder sits at the web root (APP_URL = '') or in a
 * subdirectory (APP_URL = '/clientflow'), and is derived rather than
 * hard-coded so the project can be renamed or moved without editing code.
 * Every internal link goes through url() rather than being written out.
 */
define('APP_URL', (static function (): string {
    if (PHP_SAPI === 'cli' || !isset($_SERVER['DOCUMENT_ROOT'])) {
        return '';
    }
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    // This file is app/config/config.php, so the project root is two levels up.
    $appRoot = str_replace('\\', '/', dirname(__DIR__, 2));
    if ($docRoot !== '' && str_starts_with($appRoot . '/', $docRoot . '/')) {
        return rtrim(substr($appRoot, strlen($docRoot)), '/');
    }
    // Not under the document root (odd setups, or the CLI): assume the root.
    return '';
})());

// Demo credentials are surfaced on the login page for convenience.
define('DEMO_ADMIN_EMAIL', 'admin@clientflow.test');
define('DEMO_ADMIN_PASS', 'admin123');
define('DEMO_STAFF_EMAIL', 'sarah@clientflow.test');
define('DEMO_STAFF_PASS', 'staff123');

date_default_timezone_set(APP_TIMEZONE);
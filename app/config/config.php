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

// --- Demo mode ---
/**
 * When true the sign-in page displays the seeded demo accounts, and the seeded
 * passwords are permitted to sign in at all.
 *
 * This defaults to true ONLY on a loopback host (XAMPP/valet/local PHP server).
 * Any deployment on a real hostname resolves to false, so the published
 * credentials are never rendered to visitors and can never be used to sign in.
 *
 * Override by defining DEMO_MODE in app/config/config.local.php, which is
 * git-ignored - set it to false when hosting a local copy on a shared machine.
 */
define('DEMO_MODE', (static function (): bool {
    if (getenv('DEMO_MODE') !== false) {
        return getenv('DEMO_MODE') === '1';
    }
    if (PHP_SAPI === 'cli') {
        return true;
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    // Strip the port before comparing, so "localhost:8080" still matches.
    $host = str_replace([':', '[', ']'], '', explode(',', $host)[0]);
    return in_array($host, ['localhost', '127.0.0.1', '::1', ''], true);
})());

// Credentials shown in the demo panel. Only ever rendered when DEMO_MODE is true.
define('DEMO_ADMIN_EMAIL', 'admin@clientflow.test');
define('DEMO_ADMIN_PASS', 'admin123');
define('DEMO_STAFF_EMAIL', 'sarah@clientflow.test');
define('DEMO_STAFF_PASS', 'staff123');

date_default_timezone_set(APP_TIMEZONE);
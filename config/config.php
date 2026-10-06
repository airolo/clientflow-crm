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

// Demo credentials are surfaced on the login page for convenience.
define('DEMO_ADMIN_EMAIL', 'admin@clientflow.test');
define('DEMO_ADMIN_PASS', 'admin123');
define('DEMO_STAFF_EMAIL', 'sarah@clientflow.test');
define('DEMO_STAFF_PASS', 'staff123');

date_default_timezone_set(APP_TIMEZONE);
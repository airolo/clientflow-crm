<?php
/**
 * Database connection (PDO singleton)
 * -------------------------------------------------------------
 * All queries across the app run through db(). It always returns the same
 * connection with exception mode on and native prepares, so every query
 * that uses ->prepare() is protected against SQL injection.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Never leak credentials or SQL in the browser.
        error_log('ClientFlow DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit(
            '<div style="font-family:system-ui;margin:3rem auto;max-width:40rem">'
            . '<h2>Database connection failed</h2>'
            . '<p>ClientFlow could not connect to MySQL. Check that:</p>'
            . '<ul>'
            . '<li>MySQL is running in the XAMPP Control Panel</li>'
            . '<li>The <code>clientflow_crm</code> database exists (import <code>database.sql</code>)</li>'
            . '<li>The credentials in <code>config/config.php</code> are correct</li>'
            . '</ul></div>'
        );
    }

    return $pdo;
}
<?php
/**
 * Database connection.
 *
 * Change the values below to match your MySQL / MariaDB server.
 * The XAMPP defaults are: host 127.0.0.1, port 3306, user root, no password.
 *
 * Each value can also be overridden with an environment variable
 * (EVAL_DB_HOST, EVAL_DB_PORT, EVAL_DB_NAME, EVAL_DB_USER, EVAL_DB_PASS),
 * which is handy when testing against a second server.
 */

define('DB_HOST', getenv('EVAL_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('EVAL_DB_PORT') ?: '3306');
define('DB_NAME', getenv('EVAL_DB_NAME') ?: 'employee_evaluation_db');
define('DB_USER', getenv('EVAL_DB_USER') ?: 'root');
define('DB_PASS', getenv('EVAL_DB_PASS') !== false ? getenv('EVAL_DB_PASS') : '');

/**
 * Returns the shared PDO connection, opening it on first use.
 * All queries in the application use prepared statements on this connection.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Make NOW() and CURRENT_TIMESTAMP use PHP's timezone, e.g. +08:00.
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }

    return $pdo;
}

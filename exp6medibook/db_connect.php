<?php
/**
 * db_connect.php — Database Connection
 * Include this file wherever you need a DB connection.
 * Usage: require_once 'db_connect.php';  then use $pdo
 */

define('DB_HOST',    'localhost');
define('DB_NAME',    'doctor_appointments');
define('DB_USER',    'root');       // ← change to your MySQL username
define('DB_PASS',    '');           // ← change to your MySQL password
define('DB_CHARSET', 'utf8mb4');

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    // Show a friendly error (never expose $e->getMessage() in production)
    http_response_code(500);
    die(json_encode([
        'status'  => 'error',
        'message' => 'Database connection failed. Check db_connect.php credentials.'
    ]));
}
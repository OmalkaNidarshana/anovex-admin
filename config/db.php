<?php
// config/db.php

define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'anovex_admin');
define('DB_USER',    'anovex_admin');   // ← your MySQL username
define('DB_PASS',    'admin@anovex2026'); // ← your MySQL password
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT
         . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }

    return $pdo;
}

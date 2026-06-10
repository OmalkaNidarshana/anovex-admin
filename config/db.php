<?php
// config/db.php — Database connection (PDO)

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'anovex_admin');
define('DB_USER', 'anovex_admin');          // change to your MySQL user
define('DB_PASS', 'admin@anovex2026');              // change to your MySQL password
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database connection failed.']);
            exit;
        }
    }

    return $pdo;
}

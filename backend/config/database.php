<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $host   = env('DB_HOST', '127.0.0.1');
        $port   = env('DB_PORT', '3306');
        $dbname = env('DB_NAME', 'wrs_accounting');
        $user   = env('DB_USER', 'root');
        $pass   = env('DB_PASS', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            die('Database connection failed. Check your .env settings.');
        }
    }

    return $pdo;
}
<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Unknown database')) {
            $bootstrap = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
            $bootstrap->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } else {
            throw $e;
        }
    }

    ensureSchema($pdo);

    return $pdo;
}

function ensureSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $checked = true;
    $usersExists = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    $settingsExists = $pdo->query("SHOW TABLES LIKE 'app_settings'")->fetchColumn();
    if ($usersExists && $settingsExists) {
        return;
    }

    $schemaPath = dirname(__DIR__) . '/database/schema.sql';
    if (!is_file($schemaPath)) {
        return;
    }

    $sql = (string)file_get_contents($schemaPath);
    $statements = preg_split('/;\s*\r?\n/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }
        $pdo->exec($statement);
    }
}


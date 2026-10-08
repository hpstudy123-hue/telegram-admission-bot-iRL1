<?php
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Africa/Cairo');

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfg = require __DIR__ . '/config.php';
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $cfg['db']['host'],
        $cfg['db']['port'] ?? '3306',
        $cfg['db']['name'],
        $cfg['db']['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $cfg['db']['user'], $cfg['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function db_setup(): void
{
    $pdo = db();
    $sql = [
        "CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            telegram_user_id BIGINT NOT NULL UNIQUE,
            username VARCHAR(255) NULL,
            first_name VARCHAR(255) NULL,
            last_name VARCHAR(255) NULL,
            state VARCHAR(50) NOT NULL DEFAULT 'idle',
            full_name VARCHAR(255) NULL,
            seat_number VARCHAR(100) NULL,
            photo_file_id VARCHAR(255) NULL,
            photo_path VARCHAR(500) NULL,
            current_application_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_state (state),
            INDEX idx_current_app (current_application_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS applications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            telegram_user_id BIGINT NOT NULL,
            username VARCHAR(255) NULL,
            full_name VARCHAR(255) NOT NULL,
            seat_number VARCHAR(100) NOT NULL,
            photo_file_id VARCHAR(255) NOT NULL,
            photo_path VARCHAR(500) NULL,
            group_chat_id BIGINT NULL,
            join_request_received TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('waiting_join','pending','approved','rejected','expired') NOT NULL DEFAULT 'waiting_join',
            admin_message_id BIGINT NULL,
            processed_by_admin BIGINT NULL,
            processed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (telegram_user_id),
            INDEX idx_status (status),
            INDEX idx_join_user (telegram_user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($sql as $query) $pdo->exec($query);
}

function setting_get(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

function setting_set(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
}

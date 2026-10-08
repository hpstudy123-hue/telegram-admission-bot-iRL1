<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram.php';

$config = require __DIR__ . '/config.php';

function tg(): TelegramApi
{
    static $api = null;
    $cfg = require __DIR__ . '/config.php';
    if (!$api) {
        $api = new TelegramApi($cfg['bot_token']);
    }
    return $api;
}

function html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_base_url(): string
{
    $cfg = require __DIR__ . '/config.php';
    if (!empty($cfg['public_base_url'])) return rtrim($cfg['public_base_url'], '/');

    $railwayDomain = trim((string) (getenv('RAILWAY_PUBLIC_DOMAIN') ?: ''));
    if ($railwayDomain !== '') return 'https://' . preg_replace('~^https?://~i', '', rtrim($railwayDomain, '/'));

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . ($dir === '/' ? '' : $dir);
}

function webhook_url(): string
{
    return app_base_url() . '/webhook.php';
}

function get_admin_ids(): array
{
    $cfg = require __DIR__ . '/config.php';
    $raw = trim((string)($cfg['admin_ids'] ?? ''));
    if ($raw === '') $raw = (string) setting_get('admin_ids', '');
    if ($raw === '') return [];
    $parts = array_filter(array_map('trim', preg_split('/[,\s]+/', $raw)));
    return array_values(array_map('intval', $parts));
}

function is_admin(int $telegramUserId): bool
{
    return in_array($telegramUserId, get_admin_ids(), true);
}

function get_group_id(): ?int
{
    $cfg = require __DIR__ . '/config.php';
    $raw = trim((string)($cfg['group_chat_id'] ?? ''));
    if ($raw === '') $raw = (string) setting_get('group_chat_id', '');
    return $raw !== '' ? (int)$raw : null;
}

function get_join_link(): ?string
{
    $link = setting_get('join_request_link');
    return $link ?: null;
}

function upsert_user(array $from): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE telegram_user_id = ? LIMIT 1');
    $stmt->execute([(int)$from['id']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $update = $pdo->prepare('UPDATE users SET username=?, first_name=?, last_name=? WHERE telegram_user_id=?');
        $update->execute([
            $from['username'] ?? null,
            $from['first_name'] ?? null,
            $from['last_name'] ?? null,
            (int)$from['id'],
        ]);
        return array_merge($existing, [
            'username' => $from['username'] ?? null,
            'first_name' => $from['first_name'] ?? null,
            'last_name' => $from['last_name'] ?? null,
        ]);
    }

    $insert = $pdo->prepare('INSERT INTO users (telegram_user_id, username, first_name, last_name, state) VALUES (?, ?, ?, ?, ?)');
    $insert->execute([
        (int)$from['id'],
        $from['username'] ?? null,
        $from['first_name'] ?? null,
        $from['last_name'] ?? null,
        'idle',
    ]);

    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function user_record(int $telegramUserId): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE telegram_user_id=? LIMIT 1');
    $stmt->execute([$telegramUserId]);
    return $stmt->fetch() ?: null;
}

function set_user_state(int $telegramUserId, string $state): void
{
    $stmt = db()->prepare('UPDATE users SET state=? WHERE telegram_user_id=?');
    $stmt->execute([$state, $telegramUserId]);
}

function set_user_fields(int $telegramUserId, array $fields): void
{
    $allowed = ['full_name', 'seat_number', 'photo_file_id', 'photo_path', 'current_application_id', 'state'];
    $sets = [];
    $params = [];
    foreach ($fields as $key => $value) {
        if (in_array($key, $allowed, true)) {
            $sets[] = $key . '=?';
            $params[] = $value;
        }
    }
    if (!$sets) return;
    $params[] = $telegramUserId;
    db()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE telegram_user_id=?')->execute($params);
}

function create_application(int $telegramUserId, string $username, string $fullName, string $seatNumber, string $photoFileId, ?string $photoPath): int
{
    $stmt = db()->prepare(
        'INSERT INTO applications (telegram_user_id, username, full_name, seat_number, photo_file_id, photo_path, status)
         VALUES (?, ?, ?, ?, ?, ?, "waiting_join")'
    );
    $stmt->execute([$telegramUserId, $username ?: null, $fullName, $seatNumber, $photoFileId, $photoPath]);
    return (int)db()->lastInsertId();
}

function application_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM applications WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function latest_application_for_user(int $telegramUserId): ?array
{
    $stmt = db()->prepare('SELECT * FROM applications WHERE telegram_user_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$telegramUserId]);
    return $stmt->fetch() ?: null;
}

function pending_application_for_user(int $telegramUserId): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM applications WHERE telegram_user_id=? AND status IN ('waiting_join','pending') ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$telegramUserId]);
    return $stmt->fetch() ?: null;
}

function mark_application_join_request(int $applicationId, int $groupId, string $status = 'pending'): void
{
    $stmt = db()->prepare('UPDATE applications SET group_chat_id=?, join_request_received=1, status=? WHERE id=?');
    $stmt->execute([$groupId, $status, $applicationId]);
}

function mark_admin_message(int $applicationId, int $messageId): void
{
    db()->prepare('UPDATE applications SET admin_message_id=? WHERE id=?')->execute([$messageId, $applicationId]);
}

function finalize_application(int $applicationId, string $status, int $adminId): void
{
    db()->prepare('UPDATE applications SET status=?, processed_by_admin=?, processed_at=NOW() WHERE id=?')
        ->execute([$status, $adminId, $applicationId]);
}


function clean_old_files(): void
{
    // Kept intentionally empty. You can add scheduled cleanup later if desired.
}

function admin_keyboard(int $applicationId, bool $active = true): array
{
    if (!$active) {
        return ['inline_keyboard' => []];
    }
    return [
        [
            ['text' => '✅ قبول', 'callback_data' => 'approve:' . $applicationId],
            ['text' => '❌ رفض', 'callback_data' => 'reject:' . $applicationId],
        ],
    ];
}

function join_keyboard(): array
{
    $link = get_join_link();
    if (!$link) {
        return ['inline_keyboard' => []];
    }
    return [
        [['text' => '🔐 طلب الانضمام للجروب', 'url' => $link]],
    ];
}

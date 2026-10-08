<?php
// Railway-ready configuration. Secrets are read from environment variables.

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return $value;
}

return [
    'bot_token' => env_value('BOT_TOKEN', ''),
    'public_base_url' => rtrim((string) env_value('PUBLIC_BASE_URL', ''), '/'),
    'webhook_secret' => env_value('WEBHOOK_SECRET', ''),
    'admin_ids' => env_value('ADMIN_IDS', ''),
    'group_chat_id' => env_value('GROUP_CHAT_ID', ''),

    'db' => [
        'host' => env_value('MYSQLHOST', env_value('DB_HOST', 'localhost')),
        'port' => env_value('MYSQLPORT', env_value('DB_PORT', '3306')),
        'name' => env_value('MYSQLDATABASE', env_value('DB_NAME', 'telegram_admission')),
        'user' => env_value('MYSQLUSER', env_value('DB_USER', 'root')),
        'pass' => env_value('MYSQLPASSWORD', env_value('DB_PASSWORD', '')),
        'charset' => 'utf8mb4',
    ],

    'timezone' => 'Africa/Cairo',
];

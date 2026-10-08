<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram.php';

$config = require __DIR__ . '/config.php';
$error = '';
$success = '';
$joinLink = setting_get('join_request_link');
$webhookUrl = setting_get('webhook_url');

try {
    db_setup();
} catch (Throwable $e) {
    $error = 'قاعدة البيانات غير جاهزة: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    try {
        $token = trim((string)$config['bot_token']);
        $groupId = trim((string)($config['group_chat_id'] ?? ''));
        $adminIds = trim((string)($config['admin_ids'] ?? ''));
        $secret = trim((string)($config['webhook_secret'] ?? ''));

        if ($token === '') throw new RuntimeException('BOT_TOKEN غير موجود في Railway Variables.');
        if ($groupId === '' || !preg_match('/^-?\d+$/', $groupId)) throw new RuntimeException('GROUP_CHAT_ID غير صحيح.');
        if ($adminIds === '') throw new RuntimeException('ADMIN_IDS غير موجود.');
        if ($secret === '') throw new RuntimeException('WEBHOOK_SECRET غير موجود.');

        $api = new TelegramApi($token);
        $me = $api->getMe();
        $chat = $api->getChat((int)$groupId);
        if (!in_array($chat['type'] ?? '', ['group', 'supergroup'], true)) {
            throw new RuntimeException('GROUP_CHAT_ID ليس Group/Supergroup.');
        }

        $invite = $api->createChatInviteLink((int)$groupId, 'Admission Bot');
        $joinLink = (string)($invite['invite_link'] ?? '');
        if ($joinLink === '') throw new RuntimeException('لم أستطع إنشاء رابط Join Request. تأكد أن البوت Admin ومعه صلاحية Invite Users.');

        $base = trim((string)($config['public_base_url'] ?? ''));
        if ($base === '') {
            $domain = trim((string)getenv('RAILWAY_PUBLIC_DOMAIN'));
            if ($domain !== '') $base = 'https://' . preg_replace('~^https?://~i', '', rtrim($domain, '/'));
        }
        if ($base === '') throw new RuntimeException('أنشئ Domain للخدمة في Railway أولًا، أو ضع PUBLIC_BASE_URL.');
        $base = rtrim($base, '/');

        $webhookUrl = $base . '/webhook.php';
        $api->setWebhook($webhookUrl, $secret);

        setting_set('admin_ids', $adminIds);
        setting_set('group_chat_id', $groupId);
        setting_set('join_request_link', $joinLink);
        setting_set('bot_username', '@' . ($me['username'] ?? ''));
        setting_set('webhook_url', $webhookUrl);

        $success = 'تم تشغيل البوت وربط الـ Webhook بنجاح ✅';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>تشغيل البوت</title>
<style>
body{margin:0;background:#f4f7fb;font-family:Tahoma,Arial,sans-serif;color:#18212f}.wrap{max-width:760px;margin:40px auto;padding:20px}.card{background:#fff;border-radius:18px;padding:26px;box-shadow:0 10px 35px #00000010;margin-bottom:18px}.btn{border:0;border-radius:12px;padding:15px 22px;background:#2563eb;color:#fff;font-weight:700;font-size:16px;cursor:pointer}.ok,.err{padding:15px;border-radius:12px}.ok{background:#ecfdf3;color:#166534;border:1px solid #bbf7d0}.err{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3}.mono{direction:ltr;text-align:left;background:#f7f8fa;padding:10px;border-radius:10px;word-break:break-all}.small{font-size:14px;color:#667085;line-height:1.8}
</style>
</head>
<body><div class="wrap">
<div class="card"><h1>🤖 تشغيل البوت</h1><p class="small">كل الإعدادات مأخوذة من Railway Variables. اضغط الزر مرة واحدة بعد إضافة البوت للجروب ووضع Group ID وAdmin ID.</p></div>
<?php if($error): ?><div class="card err">❌ <?=h($error)?></div><?php endif; ?>
<?php if($success): ?><div class="card ok">✅ <?=h($success)?></div><?php endif; ?>
<form method="post" class="card"><button class="btn" type="submit">🚀 تشغيل وربط البوت</button></form>
<?php if($webhookUrl): ?><div class="card"><b>Webhook:</b><div class="mono"><?=h($webhookUrl)?></div></div><?php endif; ?>
<?php if($joinLink): ?><div class="card"><b>رابط طلب الانضمام:</b><div class="mono"><?=h($joinLink)?></div></div><?php endif; ?>
<div class="card small">بعد نجاح التشغيل، لا تجعل صفحة setup.php متاحة للعامة. احذفها من GitHub بعد التشغيل أو احمها/احذفها من المشروع.</div>
</div></body></html>

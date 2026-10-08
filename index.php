<?php
header('Content-Type: text/html; charset=utf-8');
$config = require __DIR__ . '/config.php';
$ready = trim((string)$config['bot_token']) !== '' && trim((string)$config['group_chat_id']) !== '' && trim((string)$config['admin_ids']) !== '';
?><!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Telegram Admission Bot</title>
<style>body{font-family:Tahoma,Arial;margin:0;background:#f6f8fb;display:grid;place-items:center;min-height:100vh}.card{background:#fff;padding:30px;border-radius:20px;box-shadow:0 15px 50px #00000012;max-width:650px;text-align:center}a,button{display:inline-block;background:#2563eb;color:#fff;padding:13px 18px;border-radius:11px;text-decoration:none;border:0;font-weight:700}code{background:#f0f2f5;padding:3px 6px;border-radius:6px}</style></head>
<body><div class="card"><h1>🤖 Telegram Admission Bot</h1><p><?= $ready ? 'البوت جاهز للإعداد.' : 'أضف Railway Variables أولاً، ثم افتح setup.php.' ?></p><a href="setup.php">⚙️ تشغيل البوت</a></div></body></html>

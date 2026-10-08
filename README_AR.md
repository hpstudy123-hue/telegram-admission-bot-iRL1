# Telegram Admission Bot — Railway + GitHub

بوت تسجيل طلاب ثم مراجعة طلبات الانضمام للجروب.

## التدفق

1. الطالب يرسل `/start`.
2. البوت يطلب الاسم الرباعي.
3. ثم رقم الجلوس.
4. ثم صورة.
5. البوت يعطيه رابط Join Request للجروب.
6. عند إرسال الطالب طلب الانضمام، يصل للأدمن الاسم + رقم الجلوس + الصورة + زري قبول/رفض.
7. قبول = `approveChatJoinRequest` ودخول الطالب تلقائيًا.
8. رفض = `declineChatJoinRequest`.

الصورة لا يتم تخزينها على السيرفر؛ يتم استخدام Telegram `file_id` وإرسالها مباشرة للأدمن، وهذا مناسب لـ Railway لأن القرص المحلي ليس مكانًا مناسبًا لتخزين ملفات دائمة.

## أهم تعديل

لا يوجد Token أو باسورد قاعدة بيانات داخل ملفات المشروع. استخدم Railway Variables.

## المتطلبات

- GitHub repository.
- Railway account.
- Telegram Bot من BotFather.
- Group/Supergroup.
- MySQL service على Railway.

## Railway Variables

أضف في خدمة البوت:

- `BOT_TOKEN` = توكن البوت.
- `ADMIN_IDS` = Telegram ID للأدمن، ويمكن أكثر من ID بفواصل.
- `GROUP_CHAT_ID` = ID الجروب مثل `-1001234567890`.
- `WEBHOOK_SECRET` = قيمة عشوائية طويلة.

Railway MySQL ينشئ تلقائيًا متغيرات مثل `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQLDATABASE` وتقرأها النسخة الحالية مباشرة.

`PUBLIC_BASE_URL` اختياري. بعد إنشاء Railway Domain، يمكن تركه فارغًا لأن Railway يوفر `RAILWAY_PUBLIC_DOMAIN`.

## تشغيل البوت بعد Deploy

افتح:

`https://YOUR-RAILWAY-DOMAIN/setup.php`

ثم اضغط:

`🚀 تشغيل وربط البوت`

سيقوم السكربت بإنشاء الجداول، إنشاء رابط Join Request، وضبط Telegram Webhook.

## مهم

- البوت لازم يكون Admin في الجروب.
- لازم يكون معه صلاحية دعوة المستخدمين/إدارة طلبات الانضمام.
- الـ Webhook لازم يكون على HTTPS.
- لا تضع `BOT_TOKEN` في GitHub.
- بعد تشغيل `setup.php` احذف `setup.php` من GitHub أو اجعله غير متاح للعامة.

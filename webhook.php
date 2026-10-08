<?php

require_once __DIR__ . '/helpers.php';

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$expectedSecret = $config['webhook_secret'] ?? '';
$receivedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($expectedSecret !== '' && !hash_equals($expectedSecret, $receivedSecret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$raw = file_get_contents('php://input');
$update = json_decode($raw, true);
if (!is_array($update)) {
    echo json_encode(['ok' => true]);
    exit;
}

try {
    if (isset($update['message'])) {
        handle_message($update['message']);
    } elseif (isset($update['callback_query'])) {
        handle_callback($update['callback_query']);
    } elseif (isset($update['chat_join_request'])) {
        handle_join_request($update['chat_join_request']);
    }
} catch (Throwable $e) {
    @file_put_contents(
        __DIR__ . '/storage/logs/webhook.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n",
        FILE_APPEND
    );
}

echo json_encode(['ok' => true]);

function handle_message(array $message): void
{
    $from = $message['from'] ?? null;
    if (!$from || !isset($from['id'])) {
        return;
    }

    $telegramUserId = (int)$from['id'];
    $text = trim((string)($message['text'] ?? ''));

    // أمر مفيد أثناء الإعداد لمعرفة ID الجروب مباشرة.
    if ($text === '/chatid' && in_array(($message['chat']['type'] ?? ''), ['group', 'supergroup'], true)) {
        tg()->sendMessage($message['chat']['id'], '🆔 Group Chat ID: <code>' . (int)$message['chat']['id'] . '</code>');
        return;
    }

    if (($message['chat']['type'] ?? '') !== 'private') {
        return;
    }

    $user = upsert_user($from);

    if ($text === '/myid') {
        tg()->sendMessage($telegramUserId, '🆔 رقم حسابك في Telegram هو: <code>' . $telegramUserId . '</code>');
        return;
    }

    if ($text === '/restart') {
        $existing = pending_application_for_user($telegramUserId);
        if ($existing) {
            if ($existing['status'] === 'pending' && !empty($existing['group_chat_id'])) {
                try {
                    tg()->declineChatJoinRequest((int)$existing['group_chat_id'], $telegramUserId);
                } catch (Throwable $e) {
                    // لو كان الطلب اختفى بالفعل، نكمل بإلغاء الطلب المحلي.
                }
            }
            finalize_application((int)$existing['id'], 'expired', $telegramUserId);
        }

        set_user_fields($telegramUserId, [
            'full_name' => null,
            'seat_number' => null,
            'photo_file_id' => null,
            'photo_path' => null,
            'current_application_id' => null,
            'state' => 'awaiting_name',
        ]);
        tg()->sendMessage($telegramUserId, 'بدأنا طلبًا جديدًا ✅\n\nاكتب <b>الاسم الرباعي</b> كاملًا.');
        return;
    }

    if ($text === '/start' || $text === 'ابدأ من جديد') {
        $existing = pending_application_for_user($telegramUserId);
        if ($existing) {
            tg()->sendMessage(
                $telegramUserId,
                'لديك طلب قائم بالفعل.\n\nرقم الطلب: <b>#' . $existing['id'] . '</b>\nالحالة: <b>' . status_ar($existing['status']) . '</b>\n\nلإلغاء الطلب والبدء من جديد أرسل <b>/restart</b>.'
            );
            if ($existing['status'] === 'waiting_join' && get_join_link()) {
                tg()->sendMessage($telegramUserId, 'اضغط الزر التالي لإرسال طلب الانضمام للجروب:', join_keyboard());
            }
            return;
        }

        set_user_fields($telegramUserId, [
            'full_name' => null,
            'seat_number' => null,
            'photo_file_id' => null,
            'photo_path' => null,
            'current_application_id' => null,
            'state' => 'awaiting_name',
        ]);

        tg()->sendMessage($telegramUserId, 'أهلاً بك 👋\n\nاكتب <b>الاسم الرباعي</b> كاملًا كما هو في المستند.');
        return;
    }

    $state = $user['state'] ?? 'idle';

    if ($state === 'awaiting_name') {
        if ($text === '' || mb_strlen($text) < 5 || preg_match('/https?:\/\//i', $text)) {
            tg()->sendMessage($telegramUserId, 'من فضلك اكتب الاسم الرباعي كاملًا، مثال:\n<b>أحمد محمد علي محمود</b>');
            return;
        }

        set_user_fields($telegramUserId, ['full_name' => $text, 'state' => 'awaiting_seat']);
        tg()->sendMessage($telegramUserId, 'تمام ✅\n\nدلوقتي اكتب <b>رقم الجلوس</b>.');
        return;
    }

    if ($state === 'awaiting_seat') {
        if ($text === '' || !preg_match('/^[0-9]{1,20}$/', $text)) {
            tg()->sendMessage($telegramUserId, 'رقم الجلوس لازم يكون أرقام فقط، مثال: <b>123456</b>');
            return;
        }

        set_user_fields($telegramUserId, ['seat_number' => $text, 'state' => 'awaiting_photo']);
        tg()->sendMessage($telegramUserId, 'تمام ✅\n\nآخر خطوة: ابعت <b>صورة واضحة</b> للمستند المطلوب.\n\nيفضل تكون الصورة واضحة ومش مقصوصة.');
        return;
    }

    if ($state === 'awaiting_photo') {
        if (empty($message['photo']) || !is_array($message['photo'])) {
            tg()->sendMessage($telegramUserId, 'من فضلك ابعت <b>صورة</b> فقط، مش ملف PDF.');
            return;
        }

        $photos = $message['photo'];
        $largest = end($photos);
        $fileId = $largest['file_id'] ?? null;
        if (!$fileId) {
            tg()->sendMessage($telegramUserId, 'حصل خطأ في قراءة الصورة. ابعتها مرة تانية.');
            return;
        }

        // Telegram file_id can be reused directly with sendPhoto.
        // We do not download/store the image locally, which keeps Railway deployments stateless.
        $localPath = null;

        $fullName = trim((string)$user['full_name']);
        $seat = trim((string)$user['seat_number']);
        $username = (string)($from['username'] ?? '');
        $applicationId = create_application($telegramUserId, $username, $fullName, $seat, $fileId, $localPath);

        set_user_fields($telegramUserId, [
            'photo_file_id' => $fileId,
            'photo_path' => null,
            'current_application_id' => $applicationId,
            'state' => 'waiting_join',
        ]);

        tg()->sendMessage(
            $telegramUserId,
            "تم استلام بياناتك وصورتك ✅\n\n<b>الاسم:</b> " . html($fullName) . "\n<b>رقم الجلوس:</b> " . html($seat) . "\n\nالخطوة الأخيرة: اضغط الزر بالأسفل واعمل <b>طلب انضمام</b> للجروب. بعد الطلب، الأدمن هيراجع بياناتك.",
            join_keyboard()
        );
        return;
    }

    if ($state === 'waiting_join') {
        $app = pending_application_for_user($telegramUserId);
        if ($app && $app['status'] === 'waiting_join') {
            tg()->sendMessage($telegramUserId, 'بياناتك اتسجلت ✅\nاضغط زر <b>طلب الانضمام للجروب</b> الموجود في الرسالة السابقة.');
            return;
        }
    }

    tg()->sendMessage($telegramUserId, 'أرسل <b>/start</b> للبدء أو لإعادة التقديم.');
}

function handle_join_request(array $request): void
{
    $from = $request['from'] ?? [];
    $chat = $request['chat'] ?? [];
    $userId = (int)($from['id'] ?? 0);
    $chatId = (int)($chat['id'] ?? 0);
    if ($userId === 0 || $chatId === 0) return;

    $app = pending_application_for_user($userId);
    if (!$app) {
        // طلب انضمام بدون تقديم مسبق: نرفضه فورًا لأن البوت لا يملك بيانات الطالب.
        try {
            tg()->declineChatJoinRequest($chatId, $userId);
            tg()->sendMessage($userId, 'لازم تكمل التسجيل مع البوت الأول، وبعدها تعمل طلب الانضمام للجروب. أرسل /start.');
        } catch (Throwable $e) {
            // log below
            throw $e;
        }
        return;
    }

    mark_application_join_request((int)$app['id'], $chatId, 'pending');

    $caption =
        "📝 <b>طلب انضمام جديد</b>\n\n" .
        "👤 <b>الاسم:</b> " . html($app['full_name']) . "\n" .
        "🔢 <b>رقم الجلوس:</b> <code>" . html($app['seat_number']) . "</code>\n" .
        "🆔 <b>Telegram ID:</b> <code>" . (int)$app['telegram_user_id'] . "</code>\n" .
        "📌 <b>رقم الطلب:</b> <code>#" . (int)$app['id'] . "</code>\n\n" .
        "اختر الإجراء:";

    $messageIds = [];
    foreach (get_admin_ids() as $adminId) {
        try {
            $sent = tg()->sendPhoto($adminId, $app['photo_file_id'], $caption, admin_keyboard((int)$app['id'], true));
            $messageIds[] = (int)($sent['message_id'] ?? 0);
        } catch (Throwable $e) {
            @file_put_contents(__DIR__ . '/storage/logs/webhook.log', '[' . date('Y-m-d H:i:s') . '] admin send failed: ' . $e->getMessage() . "\n", FILE_APPEND);
        }
    }
    if ($messageIds) {
        mark_admin_message((int)$app['id'], $messageIds[0]);
    }

    tg()->sendMessage($userId, 'تم إرسال طلبك للأدمن للمراجعة ✅\nاستنى النتيجة هنا.');
}

function handle_callback(array $callback): void
{
    $from = $callback['from'] ?? [];
    $adminId = (int)($from['id'] ?? 0);
    $callbackId = (string)($callback['id'] ?? '');
    $data = (string)($callback['data'] ?? '');

    if (!is_admin($adminId)) {
        tg()->answerCallbackQuery($callbackId, 'غير مسموح لك.', true);
        return;
    }

    if (!preg_match('/^(approve|reject):(\d+)$/', $data, $m)) {
        tg()->answerCallbackQuery($callbackId, 'زر غير صالح.', true);
        return;
    }

    $action = $m[1];
    $applicationId = (int)$m[2];
    $app = application_by_id($applicationId);
    if (!$app) {
        tg()->answerCallbackQuery($callbackId, 'الطلب غير موجود.', true);
        return;
    }

    if ($app['status'] !== 'pending') {
        tg()->answerCallbackQuery($callbackId, 'الطلب تم التعامل معه بالفعل أو غير جاهز.', true);
        return;
    }

    $groupId = (int)($app['group_chat_id'] ?? 0);
    $userId = (int)$app['telegram_user_id'];

    if ($action === 'approve') {
        try {
            tg()->approveChatJoinRequest($groupId, $userId);
            finalize_application($applicationId, 'approved', $adminId);
            tg()->answerCallbackQuery($callbackId, 'تمت الموافقة ✅');
            tg()->sendMessage($userId, '🎉 تم قبول طلبك، ودخلت الجروب بنجاح.');
            disable_callback_buttons($callback, '✅ تمت الموافقة على الطلب');
        } catch (Throwable $e) {
            tg()->answerCallbackQuery($callbackId, 'فشل القبول: ' . mb_substr($e->getMessage(), 0, 160), true);
        }
        return;
    }

    try {
        tg()->declineChatJoinRequest($groupId, $userId);
        finalize_application($applicationId, 'rejected', $adminId);
        tg()->answerCallbackQuery($callbackId, 'تم الرفض ❌');
        tg()->sendMessage($userId, '❌ تم رفض طلب الانضمام. لو شايف إن فيه مشكلة، أرسل /start وأعد التقديم.');
        disable_callback_buttons($callback, '❌ تم رفض الطلب');
    } catch (Throwable $e) {
        tg()->answerCallbackQuery($callbackId, 'فشل الرفض: ' . mb_substr($e->getMessage(), 0, 160), true);
    }
}

function disable_callback_buttons(array $callback, string $statusText): void
{
    $message = $callback['message'] ?? [];
    $chatId = $message['chat']['id'] ?? null;
    $messageId = $message['message_id'] ?? null;
    if ($chatId === null || $messageId === null) return;
    try {
        tg()->editMessageReplyMarkup($chatId, $messageId, ['inline_keyboard' => []]);
    } catch (Throwable $e) {
        // no-op
    }
}

function status_ar(string $status): string
{
    return match ($status) {
        'waiting_join' => 'في انتظار طلب الانضمام',
        'pending' => 'قيد مراجعة الأدمن',
        'approved' => 'مقبول',
        'rejected' => 'مرفوض',
        default => $status,
    };
}

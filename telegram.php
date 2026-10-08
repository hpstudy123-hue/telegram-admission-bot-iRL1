<?php

class TelegramApi
{
    private string $token;
    private string $apiBase;

    public function __construct(string $token)
    {
        if ($token === '' || $token === 'PUT_BOT_TOKEN_HERE') {
            throw new RuntimeException('Bot token is not configured.');
        }
        $this->token = $token;
        $this->apiBase = 'https://api.telegram.org/bot' . $token . '/';
    }

    public function call(string $method, array $params = [], bool $multipart = false): array
    {
        $url = $this->apiBase . $method;
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        if ($multipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        } else {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Telegram cURL error: ' . $error);
        }

        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid Telegram response (HTTP ' . $http . ').');
        }

        if (($data['ok'] ?? false) !== true) {
            throw new RuntimeException(($data['description'] ?? 'Telegram API error') . ' [HTTP ' . $http . ']');
        }

        return $data['result'];
    }

    public function sendMessage($chatId, string $text, ?array $replyMarkup = null, array $extra = []): array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra);
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $this->call('sendMessage', $params);
    }

    public function sendPhoto($chatId, string $photo, string $caption = '', ?array $replyMarkup = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $this->call('sendPhoto', $params);
    }

    public function answerCallbackQuery(string $callbackId, string $text = '', bool $showAlert = false): array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => $showAlert,
        ]);
    }

    public function editMessageReplyMarkup($chatId, $messageId, ?array $replyMarkup): array
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode($replyMarkup ?? ['inline_keyboard' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        return $this->call('editMessageReplyMarkup', $params);
    }

    public function getFile(string $fileId): array
    {
        return $this->call('getFile', ['file_id' => $fileId]);
    }

    public function downloadFile(string $filePath, string $destination): void
    {
        $url = 'https://api.telegram.org/file/bot' . $this->token . '/' . ltrim($filePath, '/');
        $fp = fopen($destination, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Cannot open destination file for writing.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ]);
        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $http < 200 || $http >= 300) {
            @unlink($destination);
            throw new RuntimeException('Telegram file download failed: ' . ($error ?: 'HTTP ' . $http));
        }
    }

    public function approveChatJoinRequest($chatId, $userId): array
    {
        return $this->call('approveChatJoinRequest', ['chat_id' => $chatId, 'user_id' => $userId]);
    }

    public function declineChatJoinRequest($chatId, $userId): array
    {
        return $this->call('declineChatJoinRequest', ['chat_id' => $chatId, 'user_id' => $userId]);
    }

    public function createChatInviteLink($chatId, string $name = 'Admission Bot'): array
    {
        return $this->call('createChatInviteLink', [
            'chat_id' => $chatId,
            'name' => $name,
            'creates_join_request' => true,
            'member_limit' => 0,
        ]);
    }

    public function setWebhook(string $url, string $secretToken): array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => ['message', 'callback_query', 'chat_join_request', 'my_chat_member'],
            'drop_pending_updates' => false,
        ]);
    }

    public function getMe(): array
    {
        return $this->call('getMe');
    }

    public function getChat($chatId): array
    {
        return $this->call('getChat', ['chat_id' => $chatId]);
    }
}

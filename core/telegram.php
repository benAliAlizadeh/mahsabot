<?php
/**
 * MahsaBot - Telegram Bot API Wrapper
 * Clean, reusable functions for all Telegram interactions
 * 
 * @package MahsaBot
 */

/**
 * Send a request to Telegram Bot API
 */
function tg_request(string $method, array $params = []) {
    $url = 'https://api.telegram.org/bot' . ESI_BOT_TOKEN . '/' . $method;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('MahsaBot TG API Error: ' . curl_error($ch));
        curl_close($ch);
        return (object)['ok' => false, 'description' => 'Connection error'];
    }
    curl_close($ch);
    return json_decode($response) ?? (object)['ok' => false];
}

/**
 * Send a text message
 */
function tg_send(string $text, $keyboard = null, string $parse = 'MarkDown', $chatId = null, $replyTo = null) {
    global $fromId;
    $params = [
        'chat_id'    => $chatId ?? $fromId,
        'text'       => $text,
        'parse_mode' => $parse,
    ];
    if ($keyboard !== null) $params['reply_markup'] = $keyboard;
    if ($replyTo !== null)  $params['reply_to_message_id'] = $replyTo;
    return tg_request('sendMessage', $params);
}

/**
 * Edit message text
 */
function tg_edit(int $messageId, string $text, $keyboard = null, string $parse = null, $chatId = null) {
    global $fromId;
    $params = [
        'chat_id'    => $chatId ?? $fromId,
        'message_id' => $messageId,
        'text'       => $text,
    ];
    if ($parse !== null)    $params['parse_mode'] = $parse;
    if ($keyboard !== null) $params['reply_markup'] = $keyboard;
    return tg_request('editMessageText', $params);
}

/**
 * Edit message keyboard only
 */
function tg_edit_keys($keyboard = null, int $messageId = null, $chatId = null) {
    global $fromId, $msgId;
    return tg_request('editMessageReplyMarkup', [
        'chat_id'      => $chatId ?? $fromId,
        'message_id'   => $messageId ?? $msgId,
        'reply_markup'  => $keyboard,
    ]);
}

/**
 * Delete a message
 */
function tg_delete(int $messageId = null, $chatId = null) {
    global $fromId, $msgId;
    return tg_request('deleteMessage', [
        'chat_id'    => $chatId ?? $fromId,
        'message_id' => $messageId ?? $msgId,
    ]);
}

/**
 * Show callback query alert/toast
 */
function tg_alert(string $text, bool $showAlert = false, string $overrideCallbackId = null) {
    global $callbackId;
    return tg_request('answerCallbackQuery', [
        'callback_query_id' => $overrideCallbackId ?? $callbackId,
        'text'              => $text,
        'show_alert'        => $showAlert,
    ]);
}

/**
 * Send a photo
 */
function tg_photo($photo, string $caption = null, $keyboard = null, string $parse = 'MarkDown', $chatId = null) {
    global $fromId;
    $params = [
        'chat_id'    => $chatId ?? $fromId,
        'photo'      => $photo,
        'parse_mode' => $parse,
    ];
    if ($caption !== null)  $params['caption'] = $caption;
    if ($keyboard !== null) $params['reply_markup'] = $keyboard;
    return tg_request('sendPhoto', $params);
}

/**
 * Send a document
 */
function tg_document($document, string $caption = null, $keyboard = null, string $parse = 'MarkDown', $chatId = null) {
    global $fromId;
    $params = [
        'chat_id'  => $chatId ?? $fromId,
        'document' => $document,
    ];
    if ($caption !== null)  $params['caption'] = $caption;
    if ($keyboard !== null) $params['reply_markup'] = $keyboard;
    if ($parse !== null)    $params['parse_mode'] = $parse;
    return tg_request('sendDocument', $params);
}

/**
 * Send chat action (typing, uploading, etc.)
 */
function tg_action(string $action = 'typing', $chatId = null) {
    global $fromId;
    return tg_request('sendChatAction', [
        'chat_id' => $chatId ?? $fromId,
        'action'  => $action,
    ]);
}

/**
 * Forward a message
 */
function tg_forward($toChatId, $fromChatId, int $messageId) {
    return tg_request('forwardMessage', [
        'chat_id'      => $toChatId,
        'from_chat_id' => $fromChatId,
        'message_id'   => $messageId,
    ]);
}

/**
 * Get file download URL
 */
function tg_file_url(string $fileId): string {
    $result = tg_request('getFile', ['file_id' => $fileId]);
    $filePath = $result->result->file_path ?? '';
    return 'https://api.telegram.org/file/bot' . ESI_BOT_TOKEN . '/' . $filePath;
}

/**
 * Send media based on type
 */
function tg_send_media(string $type, $fileId, string $caption = null, $keyboard = null, $chatId = null) {
    global $fromId;
    $chatId = $chatId ?? $fromId;
    $params = ['chat_id' => $chatId];
    if ($caption !== null)  $params['caption'] = $caption;
    if ($keyboard !== null) $params['reply_markup'] = $keyboard;
    
    $methodMap = [
        'photo'    => ['sendPhoto', 'photo'],
        'video'    => ['sendVideo', 'video'],
        'audio'    => ['sendAudio', 'audio'],
        'voice'    => ['sendVoice', 'voice'],
        'document' => ['sendDocument', 'document'],
    ];
    
    if (isset($methodMap[$type])) {
        $params[$methodMap[$type][1]] = $fileId;
        return tg_request($methodMap[$type][0], $params);
    }
    return (object)['ok' => false];
}

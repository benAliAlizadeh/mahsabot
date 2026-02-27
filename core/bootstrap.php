<?php
/**
 * MahsaBot - Telegram VPN Service Bot
 * Core Bootstrap - Initializes all dependencies and connections
 * 
 * @package MahsaBot
 * @version 1.0.0
 */

// Load configuration (primary: config.php, fallback: app.conf.php)
$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    $legacyPath = __DIR__ . '/../app.conf.php';
    if (file_exists($legacyPath)) {
        $configPath = $legacyPath;
    } else {
        die('MahsaBot: Configuration file not found. Run the installer first.');
    }
}
require_once $configPath;

// Auto-loader for core modules
$coreModules = [
    'database.php',
    'telegram.php',
    'helpers.php',
    'middleware.php',
];
foreach ($coreModules as $module) {
    require_once __DIR__ . '/' . $module;
}

// Load locale
require_once __DIR__ . '/../locale/messages.php';
require_once __DIR__ . '/../locale/buttons.php';

// Load Jalali date library
require_once __DIR__ . '/../lib/jdf.php';

// Load panel API modules
require_once __DIR__ . '/../panels/xui.php';
require_once __DIR__ . '/../panels/marzban.php';
require_once __DIR__ . '/../panels/connection.php';

// Initialize database connection
$db = new mysqli(ESI_DB_HOST, ESI_DB_USER, ESI_DB_PASS, ESI_DB_NAME);
if ($db->connect_error) {
    error_log('MahsaBot DB Error: ' . $db->connect_error);
    exit('Service temporarily unavailable');
}
$db->set_charset('utf8mb4');

// Parse incoming Telegram update
$rawInput = file_get_contents('php://input');
$update = json_decode($rawInput);

// Extract update fields
$messageData = [];
if (isset($update->message)) {
    $messageData = [
        'from_id'    => $update->message->from->id,
        'text'       => $update->message->text ?? '',
        'first_name' => htmlspecialchars($update->message->from->first_name ?? ''),
        'last_name'  => htmlspecialchars($update->message->from->last_name ?? ''),
        'username'   => $update->message->from->username ?? 'ندارد',
        'chat_id'    => $update->message->chat->id,
        'message_id' => $update->message->message_id,
        'caption'    => $update->message->caption ?? '',
        'contact'    => $update->message->contact ?? null,
        'reply_text' => $update->message->reply_to_message->text ?? '',
        'forward_from_name' => $update->message->reply_to_message->forward_sender_name ?? '',
        'forward_from_id'   => $update->message->reply_to_message->forward_from->id ?? null,
    ];
    
    // Detect file type
    if ($update->message->document->file_id ?? false) {
        $messageData['file_type'] = 'document';
        $messageData['file_id'] = $update->message->document->file_id;
    } elseif ($update->message->audio->file_id ?? false) {
        $messageData['file_type'] = 'audio';
        $messageData['file_id'] = $update->message->audio->file_id;
    } elseif ($update->message->photo ?? false) {
        $messageData['file_type'] = 'photo';
        $photos = $update->message->photo;
        $messageData['file_id'] = end($photos)->file_id;
    } elseif ($update->message->voice->file_id ?? false) {
        $messageData['file_type'] = 'voice';
        $messageData['file_id'] = $update->message->voice->file_id;
    } elseif ($update->message->video->file_id ?? false) {
        $messageData['file_type'] = 'video';
        $messageData['file_id'] = $update->message->video->file_id;
    }
}

if (isset($update->callback_query)) {
    $messageData = array_merge($messageData, [
        'callback_id' => $update->callback_query->id,
        'callback'     => $update->callback_query->data,
        'text'         => $update->callback_query->message->text ?? '',
        'message_id'   => $update->callback_query->message->message_id,
        'chat_id'      => $update->callback_query->message->chat->id,
        'chat_type'    => $update->callback_query->message->chat->type ?? '',
        'username'     => htmlspecialchars($update->callback_query->from->username ?? 'ندارد'),
        'from_id'      => $update->callback_query->from->id,
        'first_name'   => htmlspecialchars($update->callback_query->from->first_name ?? ''),
        'markup'       => json_decode(json_encode($update->callback_query->message->reply_markup->inline_keyboard ?? []), true),
    ]);
}

// Skip group/channel messages
if (($messageData['from_id'] ?? 0) < 0) exit();

// Global shortcuts
$fromId    = $messageData['from_id'] ?? 0;
$text      = $messageData['text'] ?? '';
$data      = $messageData['callback'] ?? '';
$msgId     = $messageData['message_id'] ?? 0;
$callbackId = $messageData['callback_id'] ?? '';
$firstName = $messageData['first_name'] ?? '';
$username  = $messageData['username'] ?? '';

// Load user info
$member = esi_get_member($db, $fromId);

// Load bot options
$botOptions = esi_get_options($db, 'BOT_CONFIG');
$payKeys    = esi_get_options($db, 'GATEWAY_KEYS');

// Check channel membership
$lockChannel = $botOptions['lockChannel'] ?? '';
$memberStatus = '';
if (!empty($lockChannel)) {
    $memberStatus = tg_request('getChatMember', [
        'chat_id' => $lockChannel,
        'user_id' => $fromId
    ])->result->status ?? '';
}

// Standard keyboards
$cancelKeyboard = json_encode([
    'keyboard' => [[['text' => $btn['cancel']]]],
    'resize_keyboard' => true
]);
$removeKeyboard = json_encode(['remove_keyboard' => true]);

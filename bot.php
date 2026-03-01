<?php
/**
 * MahsaBot - Main Webhook Entry Point
 * Slim router that dispatches to handler modules
 * 
 * @package MahsaBot
 * @version 1.0.0
 */

require_once __DIR__ . '/core/bootstrap.php';

// Log fatal runtime errors so webhook retry loops can be diagnosed quickly.
register_shutdown_function(static function (): void {
    $lastError = error_get_last();
    if (!$lastError) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($lastError['type'], $fatalTypes, true)) {
        return;
    }
    $updateId = '';
    if (isset($GLOBALS['update']) && is_object($GLOBALS['update']) && isset($GLOBALS['update']->update_id)) {
        $updateId = (string)$GLOBALS['update']->update_id;
    }
    $fromId = (string)($GLOBALS['fromId'] ?? '');
    $callback = (string)($GLOBALS['data'] ?? '');
    $text = (string)($GLOBALS['text'] ?? '');
    error_log(
        'MahsaBot fatal webhook error'
        . ' update=' . $updateId
        . ' from=' . $fromId
        . ' callback=' . $callback
        . ' text=' . $text
        . ' file=' . ($lastError['file'] ?? '')
        . ' line=' . ($lastError['line'] ?? '')
        . ' message=' . ($lastError['message'] ?? '')
    );
});

// Security: Validate request comes from Telegram (mode-aware)
$ipValidationReason = '';
if (php_sapi_name() !== 'cli' && !validate_telegram_ip($ipValidationReason)) {
    error_log('MahsaBot webhook denied: ' . $ipValidationReason . ' remote=' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    http_response_code(403);
    exit('Access denied');
}

// No update? Exit cleanly
if (empty($fromId)) exit();

// Ensure member exists in DB
$member = ensure_member_exists($db, $fromId, $firstName, $username);

// Check bot state
$botActive = $botOptions['botActive'] ?? 'on';
if ($botActive === 'off' && $fromId != ESI_ADMIN_ID && ($member['is_admin'] ?? 0) != 1) {
    tg_send($msg['bot_maintenance']);
    exit();
}

// Check if user is banned
if (($member['current_step'] ?? '') === 'banned' && $fromId != ESI_ADMIN_ID && ($member['is_admin'] ?? 0) != 1) {
    tg_send($msg['account_blocked']);
    exit();
}

// Spam protection
$spamResult = check_spam($db, $fromId);
if (is_numeric($spamResult)) {
    $unbanTime = jdate('Y-m-d H:i:s', $spamResult);
    tg_send("اکانت شما به دلیل ارسال بیش از حد مسدود شده\nزمان رفع مسدودی: \n{$unbanTime}");
    exit();
}

// Channel join verification callback
if (preg_match('/^verifyJoin(.*)/', $data, $m)) {
    if (in_array($memberStatus, ['kicked', 'left'])) {
        tg_alert($msg['not_joined_yet']);
        exit();
    }
    tg_delete();
    $text = $m[1];
}

// Enforce channel join
if ($fromId != ESI_ADMIN_ID && ($member['is_admin'] ?? 0) != 1) {
    if (enforce_channel_join($memberStatus, $lockChannel, $text)) exit();
}

// Enforce phone verification
if ($fromId != ESI_ADMIN_ID && ($member['is_admin'] ?? 0) != 1) {
    if (enforce_phone_verification($db, $member, $update, $botOptions['requirePhone'] ?? 'off', $botOptions['requireIranPhone'] ?? 'off')) exit();
    // Reload member after potential phone update
    $member = esi_get_member($db, $fromId);
}

// Process referral
process_referral($db, $fromId, $text, $member);

// ─── Handler Dispatch ───────────────────────────────────────────
// Each handler file checks its own conditions and handles accordingly.
// Handlers exit() when they've processed the request, or fall through.

$step = $member['current_step'] ?? 'idle';
$isAdmin = ($fromId == ESI_ADMIN_ID || ($member['is_admin'] ?? 0) == 1);

// Handler files - order matters for step-based handlers
$handlers = [
    'start.php',      // /start, main menu, back to main
    'admin.php',      // Admin panel, settings, reports
    'server.php',     // Server management (admin)
    'category.php',   // Category management (admin)
    'plan.php',       // Plan management (admin)
    'discount.php',   // Discount code management (admin)
    'purchase.php',   // Buy subscription flow
    'account.php',    // My subscriptions, account management
    'wallet.php',     // Wallet management, cart-to-cart
    'payment.php',    // Payment processing
    'ticket.php',     // Support ticket system
    'agent.php',      // Agency/referral system
    'search.php',     // Config search
];

foreach ($handlers as $handler) {
    $handlerPath = __DIR__ . '/handlers/' . $handler;
    if (file_exists($handlerPath)) {
        require_once $handlerPath;
    }
}

// Backward-compatible alias for legacy calls that used the old shared name.
if (!function_exists('handle_cancel_transaction')) {
    function handle_cancel_transaction(int $payId): void {
        global $data;
        if (strpos((string)$data, 'cancelTransaction') === 0 && function_exists('purchase_handle_cancel_transaction')) {
            purchase_handle_cancel_transaction($payId);
            return;
        }
        if (function_exists('payment_handle_cancel_transaction')) {
            payment_handle_cancel_transaction($payId);
            return;
        }
        if (function_exists('purchase_handle_cancel_transaction')) {
            purchase_handle_cancel_transaction($payId);
        }
    }
}

// Cancel handler (universal)
if ($text === $btn['cancel']) {
    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');
    tg_send($msg['back_to_menu'], $removeKeyboard);
    if ($isAdmin) {
        tg_send($msg['admin_panel_title'], build_admin_keys());
    } else {
        tg_send($msg['welcome_message'], build_main_keys($db, $member, $botOptions, $fromId, $btn));
    }
}

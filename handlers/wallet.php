<?php
/**
 * MahsaBot - Wallet Management Handler
 * Handles wallet charging (amounts, cart-to-cart, Tron), P2P transfers,
 * and admin approval for wallet-specific receipts.
 *
 * Schema: esi_transactions uses member_id, tx_type='INCREASE_WALLET',
 *         status varchar, memo text, gateway_ref, tron_amount float.
 *         esi_members uses tg_id, balance.
 *         esi_admins uses tg_id (not user_id).
 */

if (!defined('ESI_BOT_TOKEN')) exit('No direct access.');

// Callback routes
if ($data === 'chargeWallet') {
    handle_charge_wallet();
    exit();
}

if (preg_match('/^walletAmount(\d+)$/', (string)$data, $m)) {
    handle_wallet_amount((int)$m[1]);
    exit();
}

if ($data === 'walletCustomAmount') {
    handle_wallet_custom_amount();
    exit();
}

if (preg_match('/^walletPayCart(\d+)$/', (string)$data, $m)) {
    handle_wallet_pay_cart((int)$m[1]);
    exit();
}

if (preg_match('/^walletPayTron(\d+)$/', (string)$data, $m)) {
    handle_wallet_pay_tron((int)$m[1]);
    exit();
}

if (preg_match('/^walletPayOnline_(zarinpal|nextpay)_(\d+)$/', (string)$data, $m)) {
    handle_wallet_pay_online((string)$m[1], (int)$m[2]);
    exit();
}

if (preg_match('/^approveWallet(\d+)$/', (string)$data, $m)) {
    handle_approve_wallet((int)$m[1]);
    exit();
}

if (preg_match('/^declineWallet(\d+)$/', (string)$data, $m)) {
    handle_decline_wallet((int)$m[1]);
    exit();
}

if ($data === 'transferBalance') {
    handle_transfer_balance();
    exit();
}

if ($data === 'confirmTransfer') {
    handle_confirm_transfer();
    exit();
}

// Step routes (message-only)
if ($data === '' && $step === 'enterWalletAmount' && $text !== $btn['cancel']) {
    handle_wallet_amount_input($text);
    exit();
}

if ($data === '' && preg_match('/^uploadWalletReceipt_(\d+)$/', (string)$step, $m)) {
    handle_wallet_receipt_upload((int)$m[1]);
    exit();
}

if ($data === '' && preg_match('/^enterWalletTronTxid_(\d+)$/', (string)$step, $m) && $text !== $btn['cancel']) {
    handle_wallet_tron_txid_submit((int)$m[1], $text);
    exit();
}

if ($data === '' && $step === 'enterTransferUserId' && $text !== $btn['cancel']) {
    handle_transfer_user_id_input($text);
    exit();
}

if ($data === '' && $step === 'enterTransferAmount' && $text !== $btn['cancel']) {
    handle_transfer_amount_input($text);
    exit();
}

// ─── Charge Wallet Entry ────────────────────────────────────────────────────────

function handle_charge_wallet(): void {
    global $db, $fromId, $msgId, $member, $btn, $msg;

    $balance = (int)($member['balance'] ?? 0);
    $options = esi_get_options($db, 'BOT_CONFIG');

    // Predefined amounts
    $amountsStr = $options['wallet_charge_amounts'] ?? '';
    $amounts = json_decode($amountsStr, true);
    if (empty($amounts)) $amounts = [10000, 20000, 50000, 100000, 200000, 500000];

    $keyboard = [];
    $row = [];
    foreach ($amounts as $i => $amount) {
        $row[] = ['text' => format_price($amount), 'callback_data' => 'walletAmount' . $amount];
        if (count($row) === 3 || $i === count($amounts) - 1) {
            $keyboard[] = $row;
            $row = [];
        }
    }
    $keyboard[] = [['text' => $btn['custom_amount'] ?? '✏️ مبلغ دلخواه', 'callback_data' => 'walletCustomAmount']];
    $keyboard[] = [['text' => $btn['back'] ?? '🔙 بازگشت', 'callback_data' => 'mainMenu']];

    $text = "💳 *کیف پول*\n\n💰 موجودی فعلی: " . format_price($balance) . "\n\nمبلغ شارژ را انتخاب کنید:";
    tg_edit($msgId, $text, json_encode(['inline_keyboard' => $keyboard]), 'MarkDown');
}

// ─── Select Predefined Amount ───────────────────────────────────────────────────

function handle_wallet_amount(int $amount): void {
    global $db, $fromId, $msgId, $member;

    if ($amount <= 0 || $amount > 50000000) {
        tg_alert('❌ مبلغ نامعتبر.');
        return;
    }

    // Create INCREASE_WALLET transaction
    $payId = esi_create_transaction($db, [
        'ref_code'       => 'WAL' . time(),
        'memo'           => '',
        'gateway_ref'    => '',
        'member_id'      => $fromId,
        'tx_type'        => 'INCREASE_WALLET',
        'package_id'     => 0,
        'volume'         => 0,
        'duration'       => 0,
        'amount'         => $amount,
        'created_at'     => time(),
        'status'         => 'pending',
        'agent_purchase' => 0,
        'agent_qty'      => 0,
        'tron_amount'    => 0,
    ]);

    $rows = build_wallet_payment_keys($payId);
    $rows[] = [['text' => '❌ انصراف', 'callback_data' => 'cancelTx' . $payId]];

    tg_edit($msgId, "💳 *شارژ کیف پول*\n\n💰 مبلغ: " . format_price($amount) . "\n\nروش پرداخت را انتخاب کنید:",
        json_encode(['inline_keyboard' => $rows]), 'MarkDown');
}

// ─── Custom Amount ──────────────────────────────────────────────────────────────

function handle_wallet_custom_amount(): void {
    global $db, $fromId, $msgId;

    esi_set_step($db, $fromId, 'enterWalletAmount');
    tg_edit($msgId, '✏️ مبلغ مورد نظر (به تومان) را وارد کنید:', null, 'MarkDown');
}

function handle_wallet_amount_input(string $input): void {
    global $db, $fromId, $member;

    $amount = (int)trim($input);
    if ($amount < 1000 || $amount > 50000000) {
        tg_send('❌ مبلغ باید بین 1,000 تا 50,000,000 تومان باشد.');
        return;
    }

    esi_set_step($db, $fromId, 'idle');
    handle_wallet_amount($amount);
}

// ─── Cart-to-Cart Wallet Charge ─────────────────────────────────────────────────

function handle_wallet_pay_cart(int $payId): void {
    global $db, $fromId, $msgId;

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND member_id = ? AND status = 'pending' AND tx_type = 'INCREASE_WALLET'",
        'ii', $payId, $fromId
    );
    if (!$tx) { tg_alert('❌ تراکنش یافت نشد.'); return; }

    $options    = esi_get_options($db, 'GATEWAY_KEYS');
    $cardNumber = $options['cart_card_number'] ?? '----';
    $cardHolder = $options['cart_card_holder'] ?? '----';

    esi_execute($db, "UPDATE esi_transactions SET gateway_ref = 'cart' WHERE id = ?", 'i', $payId);
    esi_set_step($db, $fromId, 'uploadWalletReceipt_' . $payId);

    $text = "🏦 *شارژ کیف پول - کارت به کارت*\n\n"
          . "💰 مبلغ: " . format_price((int)$tx['amount']) . "\n"
          . "💳 شماره کارت: `{$cardNumber}`\n"
          . "👤 صاحب کارت: {$cardHolder}\n"
          . "🔢 کد پیگیری: #{$payId}\n\n"
          . "مبلغ دقیق را واریز و تصویر رسید ارسال کنید.";

    $keyboard = json_encode(['inline_keyboard' => [
        [['text' => '❌ انصراف', 'callback_data' => 'cancelTx' . $payId]]
    ]]);

    tg_edit($msgId, $text, $keyboard, 'MarkDown');
}

function handle_wallet_receipt_upload(int $payId): void {
    global $db, $fromId, $messageData;

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND member_id = ? AND status = 'pending' AND tx_type = 'INCREASE_WALLET'",
        'ii', $payId, $fromId
    );
    if (!$tx) {
        tg_send('❌ تراکنش یافت نشد.');
        esi_set_step($db, $fromId, 'idle');
        return;
    }

    $fileId = $messageData['file_id'] ?? null;
    if (!$fileId) {
        tg_send('📷 لطفاً تصویر رسید را ارسال کنید.');
        return;
    }

    $memo = $tx['memo'] ? $tx['memo'] . "\nreceipt:" . $fileId : 'receipt:' . $fileId;
    esi_execute($db,
        "UPDATE esi_transactions SET status = 'awaiting', memo = ? WHERE id = ?",
        'si', $memo, $payId
    );

    esi_set_step($db, $fromId, 'idle');
    tg_send('✅ رسید ارسال شد! منتظر تایید ادمین بمانید.');

    // Notify admins
    notify_admins_wallet_receipt($payId, $tx, $fileId);
}

function notify_admins_wallet_receipt(int $payId, array $tx, string $fileId): void {
    global $db;

    $admins = esi_fetch_all($db, "SELECT tg_id FROM esi_admins");
    $member = esi_get_member($db, (int)$tx['member_id']);

    $text = "💳 *درخواست شارژ کیف پول*\n\n"
          . "🆔 تراکنش: #{$payId}\n"
          . "👤 " . ($member['display_name'] ?? '-') . " ({$tx['member_id']})\n"
          . "💰 مبلغ: " . format_price((int)$tx['amount']);

    $keyboard = json_encode(['inline_keyboard' => [
        [
            ['text' => '✅ تایید', 'callback_data' => 'approveWallet' . $payId],
            ['text' => '❌ رد', 'callback_data' => 'declineWallet' . $payId],
        ]
    ]]);

    foreach ($admins as $admin) {
        tg_photo($fileId, $text, $keyboard, 'MarkDown', $admin['tg_id']);
    }
}

// ─── Tron Wallet Charge ─────────────────────────────────────────────────────────

function handle_wallet_pay_tron(int $payId): void {
    global $db, $fromId, $msgId;

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND member_id = ? AND status = 'pending' AND tx_type = 'INCREASE_WALLET'",
        'ii', $payId, $fromId
    );
    if (!$tx) { tg_alert('❌ تراکنش یافت نشد.'); return; }

    $options    = esi_get_options($db, 'GATEWAY_KEYS');
    $tronWallet = $options['tron_wallet_address'] ?? '';
    $usdtRate   = (float)($options['tron_usdt_rate'] ?? 1);

    if (empty($tronWallet)) {
        tg_alert('❌ پرداخت ترون فعال نیست.');
        return;
    }

    $usdtAmount = round((int)$tx['amount'] / $usdtRate, 2);

    esi_execute($db,
        "UPDATE esi_transactions SET gateway_ref = 'tron', tron_amount = ? WHERE id = ?",
        'di', $usdtAmount, $payId
    );

    esi_set_step($db, $fromId, 'enterWalletTronTxid_' . $payId);

    $text = "🪙 *شارژ کیف پول - ترون (USDT TRC20)*\n\n"
          . "💰 مبلغ: " . format_price((int)$tx['amount']) . " = {$usdtAmount} USDT\n"
          . "📋 آدرس کیف پول: `{$tronWallet}`\n"
          . "🔢 کد پیگیری: #{$payId}\n\n"
          . "دقیقاً {$usdtAmount} USDT ارسال کرده سپس TXID پیست کنید.";

    $keyboard = json_encode(['inline_keyboard' => [
        [['text' => '❌ انصراف', 'callback_data' => 'cancelTx' . $payId]]
    ]]);

    tg_edit($msgId, $text, $keyboard, 'MarkDown');
}

function handle_wallet_tron_txid_submit(int $payId, string $txid): void {
    global $db, $fromId;

    $txid = trim($txid);
    if (strlen($txid) < 20 || !preg_match('/^[a-fA-F0-9]+$/', $txid)) {
        tg_send('❌ فرمت TXID نامعتبر.');
        return;
    }

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND member_id = ? AND status = 'pending'",
        'ii', $payId, $fromId
    );
    if (!$tx) {
        tg_send('❌ تراکنش یافت نشد.');
        esi_set_step($db, $fromId, 'idle');
        return;
    }

    $memo = $tx['memo'] ? $tx['memo'] . "\ntxid:" . $txid : 'txid:' . $txid;
    esi_execute($db,
        "UPDATE esi_transactions SET status = 'awaiting', memo = ? WHERE id = ?",
        'si', $memo, $payId
    );

    esi_set_step($db, $fromId, 'idle');
    tg_send('✅ TXID ثبت شد! تایید خودکار در حال بررسی.');
}

// ─── Admin Approve / Decline Wallet Charge ──────────────────────────────────────

function handle_approve_wallet(int $payId): void {
    global $db, $fromId, $msgId, $isAdmin;

    if (!$isAdmin) { tg_alert('❌ دسترسی ادمین لازم است.'); return; }

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND status = 'awaiting' AND tx_type = 'INCREASE_WALLET'",
        'i', $payId
    );
    if (!$tx) { tg_alert('❌ این تراکنش قبلاً بررسی شده.'); return; }

    $db->begin_transaction();
    try {
        esi_execute($db,
            "UPDATE esi_transactions SET status = 'approved' WHERE id = ?",
            'i', $payId
        );

        // Credit wallet
        esi_execute($db,
            "UPDATE esi_members SET balance = balance + ? WHERE tg_id = ?",
            'ii', (int)$tx['amount'], (int)$tx['member_id']
        );

        esi_execute($db,
            "UPDATE esi_transactions SET status = 'completed' WHERE id = ?",
            'i', $payId
        );

        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        tg_alert('❌ خطا: ' . $e->getMessage());
        return;
    }

    tg_alert('✅ شارژ کیف پول تایید شد.');
    tg_edit($msgId, "✅ *تایید شد* توسط ادمین\nتراکنش کیف پول #{$payId} | " . format_price((int)$tx['amount']),
        null, 'MarkDown');

    // Notify user
    tg_request('sendMessage', [
        'chat_id'    => $tx['member_id'],
        'text'       => '✅ کیف پول شارژ شد! +' . format_price((int)$tx['amount']),
        'parse_mode' => 'Markdown',
    ]);
}

function handle_decline_wallet(int $payId): void {
    global $db, $fromId, $msgId, $isAdmin;

    if (!$isAdmin) { tg_alert('❌ دسترسی ادمین لازم است.'); return; }

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND status = 'awaiting' AND tx_type = 'INCREASE_WALLET'",
        'i', $payId
    );
    if (!$tx) { tg_alert('❌ این تراکنش قبلاً بررسی شده.'); return; }

    esi_execute($db, "UPDATE esi_transactions SET status = 'declined' WHERE id = ?", 'i', $payId);

    tg_alert('✅ درخواست رد شد.');
    tg_edit($msgId, "❌ *رد شد* توسط ادمین\nتراکنش کیف پول #{$payId}", null, 'MarkDown');

    tg_request('sendMessage', [
        'chat_id'    => $tx['member_id'],
        'text'       => '❌ درخواست شارژ کیف پول شما (تراکنش #' . $payId . ') رد شد.',
        'parse_mode' => 'Markdown',
    ]);
}

// ─── P2P Balance Transfer ───────────────────────────────────────────────────────

function handle_transfer_balance(): void {
    global $db, $fromId, $msgId, $member, $btn;

    $balance = (int)($member['balance'] ?? 0);
    if ($balance <= 0) {
        tg_alert('❌ موجودی کافی نیست.');
        return;
    }

    esi_set_step($db, $fromId, 'enterTransferUserId');

    $keyboard = json_encode(['inline_keyboard' => [
        [['text' => $btn['cancel'] ?? '❌ انصراف', 'callback_data' => 'mainMenu']]
    ]]);

    tg_edit($msgId,
        "💸 *انتقال موجودی*\n\n💰 موجودی شما: " . format_price($balance) . "\n\nآیدی عددی کاربر مقصد را وارد کنید:",
        $keyboard, 'MarkDown'
    );
}

function handle_transfer_user_id_input(string $input): void {
    global $db, $fromId, $member;

    $targetId = (int)trim($input);
    if ($targetId <= 0 || $targetId === $fromId) {
        tg_send('❌ آیدی کاربر نامعتبر.');
        return;
    }

    $target = esi_get_member($db, $targetId);
    if (!$target) {
        tg_send('❌ کاربر یافت نشد.');
        return;
    }

    // Store target in temp
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['transfer_target'] = $targetId;
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'enterTransferAmount');

    tg_send("👤 مقصد: " . ($target['display_name'] ?? '-') . " ({$targetId})\n\nمبلغ انتقال (تومان) را وارد کنید:");
}

function handle_transfer_amount_input(string $input): void {
    global $db, $fromId, $member, $msgId;

    $amount = (int)trim($input);
    $balance = (int)($member['balance'] ?? 0);

    if ($amount <= 0 || $amount > $balance) {
        tg_send('❌ مبلغ نامعتبر یا بیشتر از موجودی.');
        return;
    }

    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $targetId = (int)($temp['transfer_target'] ?? 0);

    if ($targetId <= 0) {
        tg_send('❌ خطا. دوباره تلاش کنید.');
        esi_set_step($db, $fromId, 'idle');
        return;
    }

    $target = esi_get_member($db, $targetId);
    if (!$target) {
        tg_send('❌ کاربر مقصد یافت نشد.');
        esi_set_step($db, $fromId, 'idle');
        return;
    }

    // Confirm transfer
    $temp['transfer_amount'] = $amount;
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'confirmTransfer');

    $keyboard = json_encode(['inline_keyboard' => [
        [
            ['text' => '✅ تایید انتقال', 'callback_data' => 'confirmTransfer'],
            ['text' => '❌ انصراف', 'callback_data' => 'mainMenu'],
        ]
    ]]);

    tg_send(
        "💸 *تایید انتقال*\n\n"
        . "👤 مقصد: " . ($target['display_name'] ?? '-') . " ({$targetId})\n"
        . "💰 مبلغ: " . format_price($amount) . "\n\n"
        . "آیا مطمئن هستید؟",
        $keyboard, 'MarkDown'
    );
}

function handle_confirm_transfer(): void {
    global $db, $fromId, $msgId, $member;

    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $targetId = (int)($temp['transfer_target'] ?? 0);
    $amount   = (int)($temp['transfer_amount'] ?? 0);

    if ($targetId <= 0 || $amount <= 0) {
        tg_alert('❌ خطا. دوباره تلاش کنید.');
        esi_set_step($db, $fromId, 'idle');
        return;
    }

    $balance = (int)($member['balance'] ?? 0);
    if ($balance < $amount) {
        tg_alert('❌ موجودی ناکافی.');
        return;
    }

    $db->begin_transaction();
    try {
        esi_execute($db, "UPDATE esi_members SET balance = balance - ? WHERE tg_id = ? AND balance >= ?",
            'iii', $amount, $fromId, $amount);
        esi_execute($db, "UPDATE esi_members SET balance = balance + ? WHERE tg_id = ?",
            'ii', $amount, $targetId);
        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        tg_alert('❌ خطا در انتقال.');
        return;
    }

    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');

    tg_edit($msgId, '✅ انتقال موفق! ' . format_price($amount) . ' به کاربر ' . $targetId . ' واریز شد.',
        null, 'MarkDown');

    // Notify recipient
    tg_request('sendMessage', [
        'chat_id' => $targetId,
        'text'    => '💸 ' . format_price($amount) . ' از کاربر ' . $fromId . ' به کیف پول شما واریز شد.',
    ]);
}

// ─── Helpers ────────────────────────────────────────────────────────────────────

/**
 * Build payment method keyboard for wallet transactions.
 * (Wallet balance not shown since we're charging the wallet itself)
 */
function handle_wallet_pay_online(string $gateway, int $payId): void {
    global $db, $fromId, $msgId;

    if (!in_array($gateway, ['zarinpal', 'nextpay'], true)) {
        tg_alert('Invalid gateway.');
        return;
    }

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND member_id = ? AND status = 'pending' AND tx_type = 'INCREASE_WALLET'",
        'ii', $payId, $fromId
    );
    if (!$tx) {
        tg_alert('Transaction not found.');
        return;
    }

    $payKeys = esi_get_options($db, 'GATEWAY_KEYS');
    $gatewayConfigured = $gateway === 'zarinpal'
        ? !empty($payKeys['zarinpal_merchant'])
        : !empty($payKeys['nextpay_api_key']);
    if (!$gatewayConfigured) {
        tg_alert('Gateway is not configured.');
        return;
    }

    $baseUrl = rtrim((string)ESI_BOT_URL, '/');
    $payUrl = $baseUrl . '/gateway/initiate.php?token=' . urlencode((string)$tx['ref_code']) . '&gateway=' . urlencode($gateway);

    $gatewayTitle = $gateway === 'zarinpal' ? 'Zarinpal' : 'NextPay';
    $keyboard = json_encode(['inline_keyboard' => [
        [['text' => 'Open ' . $gatewayTitle, 'url' => $payUrl]],
        [['text' => 'Cancel', 'callback_data' => 'cancelTx' . $payId]],
    ]]);

    tg_edit($msgId,
        "*Online wallet payment*\n\n"
        . "Amount: " . format_price((int)$tx['amount']) . "\n"
        . "Transaction: #{$payId}\n\n"
        . "Use the button below to continue.",
        $keyboard,
        'MarkDown'
    );
}

function build_wallet_payment_keys(int $payId): array {
    global $db;

    $options = esi_get_options($db, 'GATEWAY_KEYS');
    $rows = [];

    // Cart-to-cart
    if (!empty($options['cart_card_number'])) {
        $rows[] = [['text' => '🏦 کارت به کارت', 'callback_data' => 'walletPayCart' . $payId]];
    }

    // Tron
    if (!empty($options['tron_wallet_address'])) {
        $rows[] = [['text' => '🪙 ترون (USDT)', 'callback_data' => 'walletPayTron' . $payId]];
    }

    // Zarinpal
    if (!empty($options['zarinpal_merchant'])) {
        $rows[] = [['text' => '💳 زرین‌پال', 'callback_data' => 'walletPayOnline_zarinpal_' . $payId]];
    }

    // NextPay
    if (!empty($options['nextpay_api_key'])) {
        $rows[] = [['text' => '💳 نکست‌پی', 'callback_data' => 'walletPayOnline_nextpay_' . $payId]];
    }

    return $rows;
}

<?php
/**
 * MahsaBot - Payment Handler
 * Processes all payment methods: wallet, cart-to-cart, Tron, online gateways.
 * Core function process_payment() handles post-payment account creation.
 *
 * Schema refs:
 *   esi_transactions: id, ref_code, memo, gateway_ref, member_id, tx_type, package_id,
 *                     volume, duration, amount, created_at, status, agent_purchase, agent_qty, tron_amount
 *   esi_subscriptions: member_id, token, tx_ref, package_id, node_id, inbound_id,
 *                      config_name, config_uuid, protocol, expires_at, connect_link, amount,
 *                      status(TINYINT), created_at, relay_mode, agent_purchase
 *   esi_members: tg_id, balance, display_name
 *   esi_admins: tg_id, display_name, role
 */

if (!defined('ESI_BOT_TOKEN')) exit('No direct access.');

// ─── Pay With Wallet Balance ────────────────────────────────────────────────────

function handle_pay_with_balance(int $payId): void {
    global $db, $fromId, $msgId, $member, $msg;

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND member_id = ? AND status = 'pending'",
        'ii', $payId, $fromId
    );

    if (!$tx) {
        tg_alert('❌ تراکنش یافت نشد یا قبلاً پردازش شده.');
        return;
    }

    $amount  = (int)$tx['amount'];
    $balance = (int)($member['balance'] ?? 0);

    if ($balance < $amount) {
        $shortage = $amount - $balance;
        tg_alert('❌ موجودی ناکافی. ' . format_price($shortage) . ' کسری دارید.', true);
        return;
    }

    // Deduct balance atomically
    $db->begin_transaction();
    try {
        esi_execute($db,
            "UPDATE esi_members SET balance = balance - ? WHERE tg_id = ? AND balance >= ?",
            'iii', $amount, $fromId, $amount
        );

        esi_execute($db,
            "UPDATE esi_transactions SET status = 'approved', gateway_ref = 'wallet' WHERE id = ?",
            'i', $payId
        );

        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        tg_alert('❌ خطا در پرداخت. دوباره تلاش کنید.');
        return;
    }

    tg_edit($msgId, '⏳ در حال پردازش پرداخت شما...', null, 'MarkDown');

    try {
        process_payment($payId);
    } catch (Exception $e) {
        // Refund on failure
        esi_execute($db, "UPDATE esi_members SET balance = balance + ? WHERE tg_id = ?", 'ii', $amount, $fromId);
        esi_execute($db, "UPDATE esi_transactions SET status = 'failed' WHERE id = ?", 'i', $payId);
        tg_edit($msgId, '❌ ساخت اکانت ناموفق بود. موجودی بازگردانده شد.', null, 'MarkDown');
    }
}

// ─── Cart-to-Cart (Bank Transfer) ───────────────────────────────────────────────

function handle_pay_with_cart(int $payId): void {
    global $db, $fromId, $msgId, $msg;

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND member_id = ? AND status = 'pending'",
        'ii', $payId, $fromId
    );
    if (!$tx) {
        tg_alert('❌ تراکنش یافت نشد.');
        return;
    }

    $options    = esi_get_options($db, 'GATEWAY_KEYS');
    $cardNumber = $options['cart_card_number'] ?? '----';
    $cardHolder = $options['cart_card_holder'] ?? '----';

    esi_execute($db, "UPDATE esi_transactions SET gateway_ref = 'cart' WHERE id = ?", 'i', $payId);
    esi_set_step($db, $fromId, 'uploadCartReceipt_' . $payId);

    $text = "🏦 *پرداخت کارت به کارت*\n\n"
          . "💰 مبلغ: " . format_price((int)$tx['amount']) . "\n"
          . "💳 شماره کارت: `{$cardNumber}`\n"
          . "👤 صاحب کارت: {$cardHolder}\n"
          . "🔢 کد پیگیری: #{$payId}\n\n"
          . "مبلغ دقیق را واریز کنید، سپس تصویر رسید را ارسال نمایید.";

    $keyboard = json_encode(['inline_keyboard' => [
        [['text' => '❌ انصراف', 'callback_data' => 'cancelTx' . $payId]]
    ]]);

    tg_edit($msgId, $text, $keyboard, 'MarkDown');
}

function handle_cart_receipt_upload(int $payId): void {
    global $db, $fromId, $messageData, $msg;

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND member_id = ? AND status = 'pending'",
        'ii', $payId, $fromId
    );
    if (!$tx) {
        tg_send('❌ تراکنش یافت نشد.');
        esi_set_step($db, $fromId, 'idle');
        return;
    }

    // Expect a photo or document
    $fileId = $messageData['file_id'] ?? null;
    if (!$fileId) {
        tg_send('📷 لطفاً تصویر رسید را ارسال کنید.');
        return;
    }

    // Store receipt file_id in memo
    $memo = $tx['memo'] ? $tx['memo'] . "\nreceipt:" . $fileId : 'receipt:' . $fileId;
    esi_execute($db,
        "UPDATE esi_transactions SET status = 'awaiting', memo = ? WHERE id = ?",
        'si', $memo, $payId
    );

    esi_set_step($db, $fromId, 'idle');
    tg_send('✅ رسید ارسال شد! لطفاً منتظر تایید ادمین بمانید.');

    // Notify admins
    notify_admins_cart_receipt($payId, $tx, $fileId);
}

function notify_admins_cart_receipt(int $payId, array $tx, string $fileId): void {
    global $db;

    $admins = esi_fetch_all($db, "SELECT tg_id FROM esi_admins");
    $member = esi_get_member($db, (int)$tx['member_id']);

    $text = "🏦 *رسید پرداخت جدید*\n\n"
          . "🆔 تراکنش: #{$payId}\n"
          . "👤 کاربر: " . ($member['display_name'] ?? '-') . " ({$tx['member_id']})\n"
          . "💰 مبلغ: " . format_price((int)$tx['amount']) . "\n"
          . "📦 نوع: {$tx['tx_type']}";

    $keyboard = json_encode(['inline_keyboard' => [
        [
            ['text' => '✅ تایید', 'callback_data' => 'approveCart' . $payId],
            ['text' => '❌ رد', 'callback_data' => 'declineCart' . $payId],
        ]
    ]]);

    foreach ($admins as $admin) {
        tg_photo($fileId, $text, $keyboard, 'MarkDown', $admin['tg_id']);
    }
}

// ─── Admin Approve / Decline Cart ───────────────────────────────────────────────

function handle_approve_cart(int $payId): void {
    global $db, $fromId, $msgId, $isAdmin;

    if (!$isAdmin) {
        tg_alert('❌ دسترسی ادمین لازم است.');
        return;
    }

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND status = 'awaiting'",
        'i', $payId
    );
    if (!$tx) {
        tg_alert('❌ این تراکنش قبلاً بررسی شده.');
        return;
    }

    esi_execute($db, "UPDATE esi_transactions SET status = 'approved' WHERE id = ?", 'i', $payId);

    try {
        process_payment($payId);
        tg_alert('✅ پرداخت تایید شد و اکانت ساخته شد.');
        tg_edit($msgId, "✅ *تایید شد* توسط ادمین\nتراکنش #{$payId} | " . format_price((int)$tx['amount']), null, 'MarkDown');
    } catch (Exception $e) {
        esi_execute($db, "UPDATE esi_transactions SET status = 'failed' WHERE id = ?", 'i', $payId);
        tg_alert('❌ تایید شد اما ساخت اکانت ناموفق: ' . $e->getMessage());
    }
}

function handle_decline_cart(int $payId): void {
    global $db, $fromId, $msgId, $isAdmin;

    if (!$isAdmin) {
        tg_alert('❌ دسترسی ادمین لازم است.');
        return;
    }

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND status = 'awaiting'",
        'i', $payId
    );
    if (!$tx) {
        tg_alert('❌ این تراکنش قبلاً بررسی شده.');
        return;
    }

    esi_execute($db, "UPDATE esi_transactions SET status = 'declined' WHERE id = ?", 'i', $payId);

    tg_alert('✅ تراکنش رد شد.');
    tg_edit($msgId, "❌ *رد شد* توسط ادمین\nتراکنش #{$payId}", null, 'MarkDown');

    // Notify user
    tg_request('sendMessage', [
        'chat_id'    => $tx['member_id'],
        'text'       => '❌ پرداخت شما (تراکنش #' . $payId . ') رد شد. در صورت اشتباه با پشتیبانی تماس بگیرید.',
        'parse_mode' => 'Markdown',
    ]);
}

// ─── Tron (TRX/USDT) Payment ────────────────────────────────────────────────────

function handle_pay_with_tron(int $payId): void {
    global $db, $fromId, $msgId, $msg;

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND member_id = ? AND status = 'pending'",
        'ii', $payId, $fromId
    );
    if (!$tx) {
        tg_alert('❌ تراکنش یافت نشد.');
        return;
    }

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

    esi_set_step($db, $fromId, 'enterTronTxid_' . $payId);

    $text = "🪙 *پرداخت ترون (USDT TRC20)*\n\n"
          . "💰 مبلغ: " . format_price((int)$tx['amount']) . " = {$usdtAmount} USDT\n"
          . "📋 آدرس کیف پول: `{$tronWallet}`\n"
          . "🔢 کد پیگیری: #{$payId}\n\n"
          . "دقیقاً {$usdtAmount} USDT ارسال کنید، سپس TXID (هش تراکنش) را اینجا پیست کنید.";

    $keyboard = json_encode(['inline_keyboard' => [
        [['text' => '❌ انصراف', 'callback_data' => 'cancelTx' . $payId]]
    ]]);

    tg_edit($msgId, $text, $keyboard, 'MarkDown');
}

function handle_tron_txid_submit(int $payId, string $txid): void {
    global $db, $fromId;

    $txid = trim($txid);
    if (strlen($txid) < 20 || !preg_match('/^[a-fA-F0-9]+$/', $txid)) {
        tg_send('❌ فرمت TXID نامعتبر است. یک هش تراکنش صحیح پیست کنید.');
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

    // Check duplicate TXID
    $duplicate = esi_fetch_one($db,
        "SELECT id FROM esi_transactions WHERE memo LIKE ? AND id != ?",
        'si', '%txid:' . $txid . '%', $payId
    );
    if ($duplicate) {
        tg_send('❌ این TXID قبلاً ثبت شده.');
        return;
    }

    // Store TXID in memo
    $memo = $tx['memo'] ? $tx['memo'] . "\ntxid:" . $txid : 'txid:' . $txid;
    esi_execute($db,
        "UPDATE esi_transactions SET status = 'awaiting', memo = ? WHERE id = ?",
        'si', $memo, $payId
    );

    esi_set_step($db, $fromId, 'idle');
    tg_send('✅ TXID ثبت شد! تایید خودکار در حال بررسی است. لطفاً منتظر بمانید.');

    // Notify admins
    notify_admins_tron_payment($payId, $tx, $txid);
}

function notify_admins_tron_payment(int $payId, array $tx, string $txid): void {
    global $db;

    $admins = esi_fetch_all($db, "SELECT tg_id FROM esi_admins");
    $member = esi_get_member($db, (int)$tx['member_id']);

    $text = "🪙 *پرداخت ترون جدید*\n\n"
          . "🆔 تراکنش: #{$payId}\n"
          . "👤 " . ($member['display_name'] ?? '-') . " ({$tx['member_id']})\n"
          . "💰 " . format_price((int)$tx['amount']) . " ({$tx['tron_amount']} USDT)\n"
          . "🔗 TXID: `{$txid}`";

    $keyboard = json_encode(['inline_keyboard' => [
        [
            ['text' => '✅ تایید', 'callback_data' => 'approveCart' . $payId],
            ['text' => '❌ رد', 'callback_data' => 'declineCart' . $payId],
        ]
    ]]);

    foreach ($admins as $admin) {
        tg_send($text, $keyboard, 'MarkDown', $admin['tg_id']);
    }
}

// ─── Cancel Transaction ─────────────────────────────────────────────────────────

function handle_cancel_transaction(int $payId): void {
    global $db, $fromId, $msgId;

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND member_id = ? AND status IN ('pending','awaiting')",
        'ii', $payId, $fromId
    );
    if (!$tx) {
        tg_alert('❌ تراکنش قابل لغو نیست.');
        return;
    }

    esi_execute($db, "UPDATE esi_transactions SET status = 'cancelled' WHERE id = ?", 'i', $payId);
    esi_set_step($db, $fromId, 'idle');
    tg_edit($msgId, '❌ تراکنش لغو شد.', null, 'MarkDown');
}

// ─── Core: Process Payment After Approval ───────────────────────────────────────

/**
 * process_payment() - Central post-payment logic
 * Reads tx_type and dispatches to create/renew/charge handlers.
 *
 * tx_type values: BUY_SUB, RENEW_ACCOUNT, INCREASE_WALLET, INCREASE_DAY, INCREASE_VOLUME
 */
function process_payment(int $payId): void {
    global $db;

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND status = 'approved'",
        'i', $payId
    );
    if (!$tx) {
        throw new Exception('تراکنش یافت نشد یا تایید نشده.');
    }

    $memberId = (int)$tx['member_id'];
    $type     = $tx['tx_type'];

    switch ($type) {
        case 'BUY_SUB':
            process_buy_subscription($tx, $memberId);
            break;
        case 'RENEW_ACCOUNT':
            process_renew_account($tx, $memberId);
            break;
        case 'INCREASE_WALLET':
            process_increase_wallet($tx, $memberId);
            break;
        case 'INCREASE_DAY':
            process_increase_day($tx, $memberId);
            break;
        case 'INCREASE_VOLUME':
            process_increase_volume($tx, $memberId);
            break;
        default:
            throw new Exception("نوع تراکنش نامشخص: {$type}");
    }

    // Mark completed
    esi_execute($db, "UPDATE esi_transactions SET status = 'completed' WHERE id = ?", 'i', $payId);
}

// ─── Process: Buy Subscription ──────────────────────────────────────────────────

function process_buy_subscription(array $tx, int $memberId): void {
    global $db;

    $pkgId = (int)$tx['package_id'];
    $pkg   = esi_fetch_one($db, "SELECT * FROM esi_packages WHERE id = ?", 'i', $pkgId);
    if (!$pkg) throw new Exception('پلن یافت نشد.');

    $nodeId = (int)$pkg['node_id'];

    // Load node_info and node_config (same ID scheme)
    $nodeInfo   = esi_fetch_one($db, "SELECT * FROM esi_node_info WHERE id = ?", 'i', $nodeId);
    $nodeConfig = esi_fetch_one($db, "SELECT * FROM esi_node_config WHERE id = ?", 'i', $nodeId);
    if (!$nodeInfo || !$nodeConfig) throw new Exception('سرور یافت نشد.');

    $uuid   = generate_uuid();
    $remark = generate_short_id();
    $token  = generate_token();

    $days   = (int)$pkg['duration'];
    $volume = (float)$pkg['volume']; // in GB

    // Call panel API
    $panelType = $nodeConfig['panel_type'] ?? 'sanaei';

    if ($panelType === 'marzban') {
        $result = marzban_add_user_account($db, $pkg, $nodeConfig, $remark, $days, $volume);
    } else {
        $result = xui_add_user_account($db, $pkg, $nodeConfig, $remark, $uuid, $days, $volume);
    }

    if (empty($result['success'])) {
        throw new Exception('خطای پنل: ' . ($result['error'] ?? 'نامشخص'));
    }

    // For Marzban, UUID may come back from API
    $actualUuid = $result['uuid'] ?? $uuid;
    $connectLink = $result['link'] ?? '';

    // Subscription expiry as unix timestamp
    $expiresAt = time() + ($days * 86400);

    // Create subscription
    $subId = esi_create_subscription($db, [
        'member_id'      => $memberId,
        'token'          => $token,
        'tx_ref'         => $tx['ref_code'] ?? (string)$tx['id'],
        'package_id'     => $pkgId,
        'node_id'        => $nodeId,
        'inbound_id'     => (int)$pkg['inbound_id'],
        'config_name'    => $remark,
        'config_uuid'    => $actualUuid,
        'protocol'       => $pkg['protocol'] ?? 'vless',
        'expires_at'     => $expiresAt,
        'connect_link'   => $connectLink,
        'amount'         => (int)$tx['amount'],
        'status'         => 1,
        'created_at'     => time(),
        'relay_mode'     => (int)($pkg['relay_mode'] ?? 0),
        'agent_purchase' => (int)$tx['agent_purchase'],
    ]);

    // Decrement capacity
    if ((int)$nodeInfo['capacity'] > 0) {
        esi_execute($db, "UPDATE esi_node_info SET capacity = GREATEST(0, capacity - 1) WHERE id = ?", 'i', $nodeId);
    }

    // Build connection link if not from Marzban
    if (empty($connectLink)) {
        $connectLink = build_subscription_link_for_user($db, $subId);
    }

    // Generate QR code
    $qrPath = generate_qr_code_for_sub($connectLink, $subId);

    // Notify user
    $member = esi_get_member($db, $memberId);
    $userMsg = build_sub_created_message($nodeInfo, $subId, $remark, $connectLink, $days, $volume, $pkg);

    if ($qrPath && file_exists($qrPath)) {
        tg_photo(new \CURLFile($qrPath), $userMsg, null, 'MarkDown', $memberId);
    } else {
        tg_request('sendMessage', [
            'chat_id'    => $memberId,
            'text'       => $userMsg,
            'parse_mode' => 'Markdown',
        ]);
    }

    // Referral reward
    handle_referral_reward($memberId, (int)$tx['amount']);

    // Notify admins
    notify_admins_new_sub($tx, $member, $nodeInfo, $subId);
}

// ─── Process: Renew Account ─────────────────────────────────────────────────────

function process_renew_account(array $tx, int $memberId): void {
    global $db;

    // Read sub ID from memo (renew:SUB_ID)
    $subId = extract_memo_value($tx['memo'], 'renew_sub');
    $sub   = esi_fetch_one($db, "SELECT * FROM esi_subscriptions WHERE id = ? AND member_id = ?", 'ii', $subId, $memberId);
    if (!$sub) throw new Exception('اشتراک یافت نشد.');

    $nodeConfig = esi_fetch_one($db, "SELECT * FROM esi_node_config WHERE id = ?", 'i', $sub['node_id']);
    if (!$nodeConfig) throw new Exception('سرور یافت نشد.');

    $days   = (int)$tx['duration'];
    $volume = (float)$tx['volume'];

    $panelType = $nodeConfig['panel_type'] ?? 'sanaei';

    if ($panelType === 'marzban') {
        $ok = marzban_edit_config($db, $nodeConfig, $sub['config_name'], 'renew', $days, $volume);
    } else {
        $result = xui_edit_traffic($db, $nodeConfig, $sub, 'renew', $days, $volume);
        $ok = !empty($result['success']);
    }

    if (!$ok) throw new Exception('خطا در تمدید روی پنل.');

    // Extend expiry from current or now (whichever is later)
    $currentExpiry = (int)$sub['expires_at'];
    $baseTime = max($currentExpiry, time());
    $newExpiry = $baseTime + ($days * 86400);

    esi_execute($db,
        "UPDATE esi_subscriptions SET expires_at = ?, status = 1 WHERE id = ?",
        'ii', $newExpiry, $subId
    );

    tg_request('sendMessage', [
        'chat_id'    => $memberId,
        'text'       => "✅ اشتراک #{$subId} تمدید شد!\n⏱ انقضای جدید: " . jdate('Y/m/d', $newExpiry),
        'parse_mode' => 'Markdown',
    ]);

    handle_referral_reward($memberId, (int)$tx['amount']);
}

// ─── Process: Increase Wallet ───────────────────────────────────────────────────

function process_increase_wallet(array $tx, int $memberId): void {
    global $db;

    $amount = (int)$tx['amount'];
    esi_execute($db, "UPDATE esi_members SET balance = balance + ? WHERE tg_id = ?", 'ii', $amount, $memberId);

    tg_request('sendMessage', [
        'chat_id'    => $memberId,
        'text'       => '✅ کیف پول شارژ شد! +' . format_price($amount),
        'parse_mode' => 'Markdown',
    ]);
}

// ─── Process: Increase Day ──────────────────────────────────────────────────────

function process_increase_day(array $tx, int $memberId): void {
    global $db;

    $subId   = extract_memo_value($tx['memo'], 'addon_sub');
    $addDays = (int)$tx['duration'];

    $sub = esi_fetch_one($db, "SELECT * FROM esi_subscriptions WHERE id = ? AND member_id = ?", 'ii', $subId, $memberId);
    if (!$sub) throw new Exception('اشتراک یافت نشد.');

    $nodeConfig = esi_fetch_one($db, "SELECT * FROM esi_node_config WHERE id = ?", 'i', $sub['node_id']);
    if (!$nodeConfig) throw new Exception('سرور یافت نشد.');

    $panelType = $nodeConfig['panel_type'] ?? 'sanaei';

    if ($panelType === 'marzban') {
        marzban_edit_config($db, $nodeConfig, $sub['config_name'], 'add_day', $addDays, 0);
    } else {
        xui_edit_traffic($db, $nodeConfig, $sub, 'add_day', $addDays, 0);
    }

    $currentExpiry = (int)$sub['expires_at'];
    $baseTime = max($currentExpiry, time());
    $newExpiry = $baseTime + ($addDays * 86400);

    esi_execute($db, "UPDATE esi_subscriptions SET expires_at = ? WHERE id = ?", 'ii', $newExpiry, $subId);

    tg_request('sendMessage', [
        'chat_id' => $memberId,
        'text'    => "✅ {$addDays} روز به اشتراک #{$subId} اضافه شد\n⏱ انقضای جدید: " . jdate('Y/m/d', $newExpiry),
    ]);
}

// ─── Process: Increase Volume ───────────────────────────────────────────────────

function process_increase_volume(array $tx, int $memberId): void {
    global $db;

    $subId = extract_memo_value($tx['memo'], 'addon_sub');
    $addGb = (float)$tx['volume'];

    $sub = esi_fetch_one($db, "SELECT * FROM esi_subscriptions WHERE id = ? AND member_id = ?", 'ii', $subId, $memberId);
    if (!$sub) throw new Exception('اشتراک یافت نشد.');

    $nodeConfig = esi_fetch_one($db, "SELECT * FROM esi_node_config WHERE id = ?", 'i', $sub['node_id']);
    if (!$nodeConfig) throw new Exception('سرور یافت نشد.');

    $panelType = $nodeConfig['panel_type'] ?? 'sanaei';

    if ($panelType === 'marzban') {
        marzban_edit_config($db, $nodeConfig, $sub['config_name'], 'add_volume', 0, $addGb);
    } else {
        xui_edit_traffic($db, $nodeConfig, $sub, 'add_volume', 0, $addGb);
    }

    tg_request('sendMessage', [
        'chat_id' => $memberId,
        'text'    => "✅ " . format_traffic($addGb) . " به اشتراک #{$subId} اضافه شد",
    ]);
}

// ─── Helpers ────────────────────────────────────────────────────────────────────

/**
 * Build subscription link for user using panel or connection module.
 */
function build_subscription_link_for_user(mysqli $db, int $subId): string {
    $sub = esi_fetch_one($db, "SELECT * FROM esi_subscriptions WHERE id = ?", 'i', $subId);
    if (!$sub) return '';

    $nodeConfig = esi_fetch_one($db, "SELECT * FROM esi_node_config WHERE id = ?", 'i', $sub['node_id']);
    if (!$nodeConfig) return '';

    $panelType = $nodeConfig['panel_type'] ?? 'sanaei';

    if ($panelType === 'marzban') {
        return marzban_get_user_link($db, $nodeConfig, $sub['config_name']);
    }

    $result = xui_get_connection_link($db, $nodeConfig, $sub);
    return $result['link'] ?? '';
}

/**
 * Build the message sent to user after subscription creation.
 */
function build_sub_created_message(array $nodeInfo, int $subId, string $remark, string $link, int $days, float $volume, array $pkg): string {
    global $msg;

    $expiryDate = jdate('Y/m/d', time() + ($days * 86400));
    $volumeStr  = $volume > 0 ? format_traffic($volume) : 'نامحدود';
    $isTest     = !empty($pkg['is_test']);

    return "✅ *اشتراک ساخته شد!*\n\n"
         . "🆔 شناسه: #{$subId}\n"
         . "🌍 سرور: " . ($nodeInfo['flag'] ?? '') . ' ' . ($nodeInfo['title'] ?? '-') . "\n"
         . "📊 حجم: {$volumeStr}\n"
         . "⏱ مدت: {$days} روز\n"
         . "📅 انقضا: {$expiryDate}\n\n"
         . "🔗 لینک اتصال:\n`{$link}`\n"
         . ($isTest ? "\n⚠️ این یک اکانت تست است." : '');
}

/**
 * Generate QR code for subscription link.
 */
function generate_qr_code_for_sub(string $data, int $subId): ?string {
    if (empty($data)) return null;

    $qrDir = __DIR__ . '/../temp/qr/';
    if (!is_dir($qrDir)) @mkdir($qrDir, 0755, true);

    $qrPath = $qrDir . $subId . '.png';
    $phpQrLib = __DIR__ . '/../lib/phpqrcode/phpqrcode.php';

    if (file_exists($phpQrLib)) {
        require_once $phpQrLib;
        QRcode::png($data, $qrPath, QR_ECLEVEL_M, 6, 2);
        return file_exists($qrPath) ? $qrPath : null;
    }

    return null;
}

/**
 * Handle referral reward.
 */
function handle_referral_reward(int $memberId, int $txAmount): void {
    global $db;

    $options    = esi_get_options($db, 'BOT_CONFIG');
    $refPercent = (int)($options['referral_percent'] ?? 0);
    if ($refPercent <= 0) return;

    $member     = esi_get_member($db, $memberId);
    $referrerId = (int)($member['referred_by'] ?? 0);
    if ($referrerId <= 0) return;

    $reward = (int)floor($txAmount * $refPercent / 100);
    if ($reward <= 0) return;

    esi_execute($db, "UPDATE esi_members SET balance = balance + ? WHERE tg_id = ?", 'ii', $reward, $referrerId);

    tg_request('sendMessage', [
        'chat_id' => $referrerId,
        'text'    => '🎁 پاداش دعوت! +' . format_price($reward) . ' از خرید زیرمجموعه شما.',
    ]);
}

/**
 * Notify admins of new subscription.
 */
function notify_admins_new_sub(array $tx, ?array $member, array $nodeInfo, int $subId): void {
    global $db;

    $admins = esi_fetch_all($db, "SELECT tg_id FROM esi_admins");
    $text   = "📦 *اشتراک جدید*\n\n"
            . "🆔 اشتراک: #{$subId} | تراکنش: #{$tx['id']}\n"
            . "👤 " . ($member['display_name'] ?? '-') . " ({$tx['member_id']})\n"
            . "🌍 " . ($nodeInfo['title'] ?? '-') . "\n"
            . "💰 " . format_price((int)$tx['amount']);

    foreach ($admins as $admin) {
        tg_request('sendMessage', [
            'chat_id'    => $admin['tg_id'],
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }
}

/**
 * Extract a value from newline-delimited memo field.
 * Format: "key:value\nkey2:value2"
 */
function extract_memo_value(string $memo, string $key): int {
    if (preg_match('/' . preg_quote($key, '/') . ':(\d+)/', $memo, $m)) {
        return (int)$m[1];
    }
    return 0;
}

/**
 * Build payment method keyboard for a transaction.
 */
function build_payment_keyboard(int $payId, int $amount, array $member): array {
    global $db;

    $options = esi_get_options($db, 'GATEWAY_KEYS');
    $rows = [];

    // Wallet balance
    $balance = (int)($member['balance'] ?? 0);
    if ($balance >= $amount) {
        $rows[] = [['text' => '💰 پرداخت از کیف پول', 'callback_data' => 'payBalance' . $payId]];
    }

    // Cart-to-cart
    if (!empty($options['cart_card_number'])) {
        $rows[] = [['text' => '🏦 کارت به کارت', 'callback_data' => 'payCart' . $payId]];
    }

    // Tron
    if (!empty($options['tron_wallet_address'])) {
        $rows[] = [['text' => '🪙 ترون (USDT)', 'callback_data' => 'payTron' . $payId]];
    }

    // Zarinpal
    if (!empty($options['zarinpal_merchant'])) {
        $rows[] = [['text' => '💳 زرین‌پال', 'callback_data' => 'payOnline_zarinpal_' . $payId]];
    }

    // NextPay
    if (!empty($options['nextpay_api_key'])) {
        $rows[] = [['text' => '💳 نکست‌پی', 'callback_data' => 'payOnline_nextpay_' . $payId]];
    }

    return $rows;
}

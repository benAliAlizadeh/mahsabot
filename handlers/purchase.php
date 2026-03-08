<?php
/**
 * MahsaBot - Purchase Handler
 * Handles the buy subscription flow: node → group → package → payment
 * Also handles custom plans, agent buys, discount codes, and free trials.
 *
 * Schema refs:
 *   esi_node_info:    id, title, flag, capacity, active, state, description
 *   esi_node_config:  id, panel_url, username, password, panel_type, ip, sni, ...
 *   esi_groups:       id, node_id, title, active, sort_order
 *   esi_packages:     id, group_id, node_id, inbound_id, title, description, protocol,
 *                     volume(FLOAT GB), duration(INT days), price, capacity, active, sort_order,
 *                     is_test, net_type, security, flow, relay_mode, ...
 *   esi_transactions: id, ref_code, memo, gateway_ref, member_id, tx_type, package_id,
 *                     volume, duration, amount, created_at, status, agent_purchase, agent_qty, tron_amount
 *   esi_subscriptions: member_id, token, tx_ref, package_id, node_id, inbound_id,
 *                      config_name, config_uuid, protocol, expires_at, connect_link, amount,
 *                      status(TINYINT), created_at, relay_mode, agent_purchase
 *   esi_coupons:      id, code, type, amount(INT), max_uses, used_by(TEXT JSON), active, expires_at, created_at
 *   esi_members:      tg_id, display_name, balance, temp_data, is_agent, trial_used, joined_at
 *
 * API conventions:
 *   esi_fetch_one($db, $query, $types, ...$params)   e.g. esi_fetch_one($db, "...", 'i', $id)
 *   esi_fetch_all($db, $query, $types, ...$params)
 *   esi_execute($db, $query, $types, ...$params)
 *   tg_edit($msgId, $text, $keyboard, $parse, $chatId)  — $msgId (global) required as 1st arg
 *   tg_send($text, $keyboard, $parse, $chatId)
 *   tg_alert($text, $showAlert, $callbackOverride)
 *   esi_get_options($db, $key)                        — requires option key string
 */

if (!defined('ESI_BOT_TOKEN')) exit('No direct access.');

// Callback routes
if ($data === 'buyService') {
    handle_buy_service();
    exit();
}

if (preg_match('/^selectNode(\d+)$/', (string)$data, $m)) {
    handle_select_node((int)$m[1]);
    exit();
}

if (preg_match('/^selectGroup(\d+)$/', (string)$data, $m)) {
    handle_select_group((int)$m[1]);
    exit();
}

if (preg_match('/^selectPackage(\d+)$/', (string)$data, $m)) {
    handle_select_package((int)$m[1]);
    exit();
}

if (preg_match('/^applyDiscount(\d+)$/', (string)$data, $m)) {
    handle_apply_discount((int)$m[1]);
    exit();
}

if ($data === 'customPlan') {
    handle_custom_plan();
    exit();
}

if ($data === 'getTestService') {
    handle_test_account();
    exit();
}

if ($data === 'agentSingleBuy' || $data === 'agentSingle') {
    handle_agent_single();
    exit();
}

if ($data === 'agentBulkBuy' || $data === 'agentBulk') {
    handle_agent_bulk();
    exit();
}

if (preg_match('/^cancelTransaction(\d+)$/', (string)$data, $m)) {
    purchase_handle_cancel_transaction((int)$m[1]);
    exit();
}

// Step routes (message-only)
if ($data === '' && preg_match('/^enterDiscount_?(\d+)$/', (string)$step, $m) && $text !== '' && $text !== $btn['cancel']) {
    handle_enter_discount((int)$m[1], $text);
    exit();
}

if ($data === '' && $step === 'customDays' && $text !== $btn['cancel']) {
    handle_custom_days($text);
    exit();
}

if ($data === '' && $step === 'customVolume' && $text !== $btn['cancel']) {
    handle_custom_volume($text);
    exit();
}

if ($data === '' && $step === 'customConfirm' && $text !== $btn['cancel']) {
    handle_custom_confirm($text);
    exit();
}

if ($data === '' && $step === 'agentBulkCount' && $text !== $btn['cancel']) {
    handle_agent_bulk_count($text);
    exit();
}

// ─── Buy Service Entry Point ───────────────────────────────────────────────────

function handle_buy_service(): void {
    global $db, $fromId, $msgId, $member, $btn, $msg, $isAdmin;

    $nodes = esi_fetch_all($db,
        "SELECT * FROM esi_node_info WHERE active = 1 ORDER BY id ASC"
    );

    if (empty($nodes)) {
        tg_edit($msgId, $msg['no_servers_available'] ?? '⚠️ سروری در دسترس نیست.', null, 'MarkDown');
        return;
    }

    $keyboard = [];
    $row = [];
    foreach ($nodes as $i => $node) {
        $label = ($node['flag'] ?? '🌐') . ' ' . $node['title'];
        if (!empty($node['capacity']) && (int)$node['capacity'] <= 0) {
            $label .= ' (تکمیل)';
        }
        $row[] = ['text' => $label, 'callback_data' => 'selectNode' . $node['id']];
        if (count($row) === 2 || $i === count($nodes) - 1) {
            $keyboard[] = $row;
            $row = [];
        }
    }
    $keyboard[] = [['text' => $btn['back'] ?? '🔙 بازگشت', 'callback_data' => 'mainMenu']];

    tg_edit($msgId, $msg['select_server'] ?? '🌍 سرور مورد نظر را انتخاب کنید:', json_encode([
        'inline_keyboard' => $keyboard
    ]), 'MarkDown');
}

// ─── Select Node → Show Groups ─────────────────────────────────────────────────

function handle_select_node(int $nodeId): void {
    global $db, $fromId, $msgId, $btn, $msg;

    $node = esi_fetch_one($db,
        "SELECT * FROM esi_node_info WHERE id = ? AND active = 1",
        'i', $nodeId
    );
    if (!$node) {
        tg_alert('❌ سرور یافت نشد.');
        return;
    }

    if (!empty($node['capacity']) && (int)$node['capacity'] <= 0) {
        tg_alert('⚠️ ظرفیت این سرور تکمیل شده.', true);
        return;
    }

    $groups = esi_fetch_all($db,
        "SELECT * FROM esi_groups WHERE node_id = ? AND active = 1 ORDER BY sort_order ASC",
        'i', $nodeId
    );
    if (empty($groups)) {
        tg_edit($msgId, '⚠️ دسته‌بندی فعالی برای این سرور وجود ندارد.', null, 'MarkDown');
        return;
    }

    esi_set_temp($db, $fromId, json_encode(['node_id' => $nodeId]));

    $keyboard = [];
    $row = [];
    foreach ($groups as $i => $group) {
        $row[] = ['text' => $group['title'], 'callback_data' => 'selectGroup' . $group['id']];
        if (count($row) === 2 || $i === count($groups) - 1) {
            $keyboard[] = $row;
            $row = [];
        }
    }
    $keyboard[] = [['text' => $btn['back'] ?? '🔙 بازگشت', 'callback_data' => 'buyService']];

    $text = '🗂 سرور: ' . $node['title'] . "\nدسته‌بندی مورد نظر را انتخاب کنید:";
    tg_edit($msgId, $text, json_encode(['inline_keyboard' => $keyboard]), 'MarkDown');
}

// ─── Select Group → Show Packages ───────────────────────────────────────────────

function handle_select_group(int $groupId): void {
    global $db, $fromId, $msgId, $btn, $msg;

    $member = esi_get_member($db, $fromId);
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $nodeId = $temp['node_id'] ?? null;
    if (!$nodeId) {
        tg_alert('❌ لطفاً از انتخاب سرور شروع کنید.');
        return;
    }

    $group = esi_fetch_one($db,
        "SELECT * FROM esi_groups WHERE id = ? AND active = 1",
        'i', $groupId
    );
    if (!$group) {
        tg_alert('❌ دسته‌بندی یافت نشد.');
        return;
    }

    $packages = esi_fetch_all($db,
        "SELECT * FROM esi_packages WHERE group_id = ? AND node_id = ? AND active = 1 AND is_test = 0 ORDER BY sort_order ASC, price ASC",
        'ii', $groupId, $nodeId
    );

    $temp['group_id'] = $groupId;
    esi_set_temp($db, $fromId, json_encode($temp));

    $keyboard = [];
    foreach ($packages as $pkg) {
        $volLabel = $pkg['volume'] > 0 ? $pkg['volume'] . ' GB' : 'نامحدود';
        $label = $pkg['title'] . ' | ' . $volLabel . ' | '
               . $pkg['duration'] . ' روز | ' . format_price((int)$pkg['price']);
        $keyboard[] = [['text' => $label, 'callback_data' => 'selectPackage' . $pkg['id']]];
    }

    // Custom plan option
    $options = esi_get_options($db, 'BOT_CONFIG');
    if (!empty($options['custom_plan_enabled'])) {
        $keyboard[] = [['text' => $btn['custom_plan'] ?? '⚙️ پلن سفارشی', 'callback_data' => 'customPlan']];
    }

    $keyboard[] = [['text' => $btn['back'] ?? '🔙 بازگشت', 'callback_data' => 'selectNode' . $nodeId]];

    $text = '📦 دسته‌بندی: ' . $group['title'] . "\nپلن مورد نظر را انتخاب کنید:";
    tg_edit($msgId, $text, json_encode(['inline_keyboard' => $keyboard]), 'MarkDown');
}

// ─── Select Package → Show Details & Payment Methods ────────────────────────────

function handle_select_package(int $packageId): void {
    global $db, $fromId, $msgId, $member, $btn, $msg;

    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $nodeId  = $temp['node_id'] ?? null;
    $groupId = $temp['group_id'] ?? null;

    if (!$nodeId || !$groupId) {
        tg_alert('❌ لطفاً از انتخاب سرور شروع کنید.');
        return;
    }

    $pkg = esi_fetch_one($db,
        "SELECT * FROM esi_packages WHERE id = ? AND active = 1",
        'i', $packageId
    );
    if (!$pkg) {
        tg_alert('❌ پلن یافت نشد.');
        return;
    }

    $node  = esi_fetch_one($db, "SELECT * FROM esi_node_info WHERE id = ?", 'i', $nodeId);
    $price = (int)$pkg['price'];

    // Apply agent discount if in agent mode
    $agentPurchase = 0;
    $agentQty      = 1;
    if (!empty($temp['agent_mode'])) {
        $agentPurchase = 1;
        if ($temp['agent_mode'] === 'bulk' && !empty($temp['bulk_count'])) {
            $agentQty = (int)$temp['bulk_count'];
        }
        // Apply agent discount config if set
        $discountCfg = json_decode($member['discount_config'] ?? '{}', true);
        if (!empty($discountCfg['percent'])) {
            $price = (int)floor($price * (100 - (int)$discountCfg['percent']) / 100);
        }
    }

    // Apply coupon discount if stored in temp
    $discountAmount = 0;
    $discountLabel  = '';
    if (!empty($temp['coupon_code'])) {
        $coupon = purchase_validate_coupon($temp['coupon_code'], $price);
        if ($coupon) {
            $discountAmount = $coupon['discount'];
            $discountLabel  = "\n🎫 تخفیف: -" . format_price($discountAmount);
            $price -= $discountAmount;
        }
    }

    $finalPrice = max(0, $price) * $agentQty;

    $temp['package_id']       = $packageId;
    $temp['final_price']      = $finalPrice;
    $temp['discount_amount']  = $discountAmount;
    $temp['pay_type']         = 'BUY_SUB';
    $temp['agent_purchase']   = $agentPurchase;
    $temp['agent_qty']        = $agentQty;
    esi_set_temp($db, $fromId, json_encode($temp));

    // Create pending transaction
    $refCode = 'TX' . time() . rand(1000, 9999);
    $payId = esi_create_transaction($db, [
        'ref_code'       => $refCode,
        'memo'           => 'خرید اشتراک - ' . $pkg['title'],
        'gateway_ref'    => '',
        'member_id'      => $fromId,
        'tx_type'        => 'BUY_SUB',
        'package_id'     => $packageId,
        'volume'         => (float)$pkg['volume'],
        'duration'       => (int)$pkg['duration'],
        'amount'         => $finalPrice,
        'created_at'     => time(),
        'status'         => 'pending',
        'agent_purchase' => $agentPurchase,
        'agent_qty'      => $agentQty,
        'tron_amount'    => 0,
    ]);

    $volLabel = $pkg['volume'] > 0 ? $pkg['volume'] . ' GB' : 'نامحدود';
    $details = "🛒 *خلاصه سفارش*\n\n"
        . "📦 پلن: {$pkg['title']}\n"
        . "🌍 سرور: " . ($node['title'] ?? '-') . "\n"
        . "📊 حجم: {$volLabel}\n"
        . "⏱ مدت: {$pkg['duration']} روز\n"
        . "💰 قیمت: " . format_price((int)$pkg['price']);

    if ($agentQty > 1) {
        $details .= "\n📦 تعداد: {$agentQty} عدد";
    }
    $details .= $discountLabel;
    if ($discountAmount > 0 || $agentQty > 1) {
        $details .= "\n✅ نهایی: " . format_price($finalPrice);
    }

    $keyboard = purchase_build_payment_keyboard($payId, $finalPrice, $member);
    $keyboard[] = [['text' => $btn['apply_discount'] ?? '🎫 کد تخفیف', 'callback_data' => 'applyDiscount' . $payId]];
    $keyboard[] = [['text' => $btn['back'] ?? '🔙 بازگشت', 'callback_data' => 'selectGroup' . $groupId]];

    tg_edit($msgId, $details, json_encode(['inline_keyboard' => $keyboard]), 'MarkDown');
}

// ─── Payment Methods Keyboard Builder ───────────────────────────────────────────

function purchase_build_payment_keyboard(int $payId, int $price, array $member): array {
    global $db, $btn;

    $options = esi_get_options($db, 'GATEWAY_KEYS');
    $keyboard = [];

    // Wallet payment
    $walletBalance = (int)($member['balance'] ?? 0);
    $walletLabel   = ($btn['pay_wallet'] ?? '💳 کیف پول') . ' (' . format_price($walletBalance) . ')';
    $keyboard[] = [['text' => $walletLabel, 'callback_data' => 'payWithBalance' . $payId]];

    // Cart-to-cart (bank transfer)
    if (!empty($options['cart_card_number'])) {
        $keyboard[] = [['text' => $btn['pay_cart'] ?? '🏦 کارت به کارت', 'callback_data' => 'payWithCart' . $payId]];
    }

    // Tron (crypto)
    $botConfig = esi_get_options($db, 'BOT_CONFIG');
    if (!empty($botConfig['tron_payment_enabled'])) {
        $keyboard[] = [['text' => $btn['pay_tron'] ?? '🪙 ترون (TRX)', 'callback_data' => 'payWithTron' . $payId]];
    }

    // Online payment gateway
    if (!empty($options['zarinpal_merchant']) || !empty($options['nextpay_api_key'])) {
        $keyboard[] = [['text' => $btn['pay_online'] ?? '🌐 پرداخت آنلاین', 'callback_data' => 'payOnline' . $payId]];
    }

    return $keyboard;
}

// ─── Discount Code Flow ─────────────────────────────────────────────────────────

function handle_apply_discount(int $payId): void {
    global $db, $fromId, $msgId, $msg;

    esi_set_step($db, $fromId, 'enterDiscount_' . $payId);
    tg_edit($msgId, $msg['enter_discount'] ?? '🎫 کد تخفیف خود را وارد کنید:', null, 'MarkDown');
}

function handle_enter_discount(int $payId, string $code): void {
    global $db, $fromId, $member, $msg;

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND member_id = ? AND status = 'pending'",
        'ii', $payId, $fromId
    );
    if (!$tx) {
        tg_send('❌ تراکنش یافت نشد.');
        esi_set_step($db, $fromId, 'idle');
        return;
    }

    // Recalculate original price from package
    $pkg = esi_fetch_one($db, "SELECT * FROM esi_packages WHERE id = ?", 'i', (int)$tx['package_id']);
    $originalPrice = $pkg ? (int)$pkg['price'] : (int)$tx['amount'];

    // Account for agent qty
    $agentQty = max(1, (int)$tx['agent_qty']);

    $coupon = purchase_validate_coupon($code, $originalPrice);
    if (!$coupon) {
        tg_send('❌ کد تخفیف نامعتبر یا منقضی شده.');
        return;
    }

    $newUnitPrice = max(0, $originalPrice - $coupon['discount']);
    $newTotal     = $newUnitPrice * $agentQty;

    // Update transaction amount
    esi_execute($db,
        "UPDATE esi_transactions SET amount = ? WHERE id = ?",
        'ii', $newTotal, $payId
    );

    // Mark coupon as used by this user
    purchase_mark_coupon_used($coupon['coupon']['id'], $fromId);

    // Update temp
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['coupon_code']     = $code;
    $temp['final_price']     = $newTotal;
    $temp['discount_amount'] = $coupon['discount'];
    esi_set_temp($db, $fromId, json_encode($temp));

    esi_set_step($db, $fromId, 'idle');
    tg_send('✅ تخفیف اعمال شد! ' . format_price($coupon['discount']) . ' صرفه‌جویی');

    // Re-show package details with updated price
    if (!empty($temp['package_id'])) {
        handle_select_package((int)$temp['package_id']);
    }
}

/**
 * Validate a coupon code against schema: esi_coupons
 *   code, type(percent/fixed), amount(INT), max_uses, used_by(TEXT JSON array), active(TINYINT), expires_at(INT)
 */
function purchase_validate_coupon(string $code, int $price): ?array {
    global $db, $fromId;

    $coupon = esi_fetch_one($db,
        "SELECT * FROM esi_coupons WHERE code = ? AND active = 1",
        's', $code
    );
    if (!$coupon) return null;

    // Check expiry (expires_at is unix timestamp; 0 = no expiry)
    if ((int)$coupon['expires_at'] > 0 && (int)$coupon['expires_at'] < time()) {
        return null;
    }

    // Parse used_by JSON array to check total usage and per-user usage
    $usedBy = json_decode($coupon['used_by'] ?? '[]', true);
    if (!is_array($usedBy)) $usedBy = [];

    // Check max_uses (0 = unlimited)
    if ((int)$coupon['max_uses'] > 0 && count($usedBy) >= (int)$coupon['max_uses']) {
        return null;
    }

    // Check if this user already used this coupon
    if (in_array($fromId, $usedBy)) {
        return null;
    }

    // Calculate discount
    $discount = 0;
    if ($coupon['type'] === 'percent') {
        $discount = (int)floor($price * (int)$coupon['amount'] / 100);
        // Cap at 100%
        $discount = min($discount, $price);
    } else {
        // Fixed amount
        $discount = min((int)$coupon['amount'], $price);
    }

    if ($discount <= 0) return null;

    return ['discount' => $discount, 'coupon' => $coupon];
}

/**
 * Mark a coupon as used by a specific user (append to used_by JSON array)
 */
function purchase_mark_coupon_used(int $couponId, int $userId): void {
    global $db;

    $coupon = esi_fetch_one($db, "SELECT used_by FROM esi_coupons WHERE id = ?", 'i', $couponId);
    if (!$coupon) return;

    $usedBy = json_decode($coupon['used_by'] ?? '[]', true);
    if (!is_array($usedBy)) $usedBy = [];

    if (!in_array($userId, $usedBy)) {
        $usedBy[]   = $userId;
        $newUsedBy  = json_encode($usedBy);
        esi_execute($db, "UPDATE esi_coupons SET used_by = ? WHERE id = ?", 'si', $newUsedBy, $couponId);
    }
}

/**
 * Reverse coupon usage when a transaction is cancelled
 */
function purchase_reverse_coupon_usage(string $code, int $userId): void {
    global $db;

    $coupon = esi_fetch_one($db, "SELECT * FROM esi_coupons WHERE code = ?", 's', $code);
    if (!$coupon) return;

    $usedBy = json_decode($coupon['used_by'] ?? '[]', true);
    if (!is_array($usedBy)) return;

    $usedBy = array_values(array_filter($usedBy, fn($uid) => $uid != $userId));
    $newUsedBy = json_encode($usedBy);
    esi_execute($db, "UPDATE esi_coupons SET used_by = ? WHERE id = ?", 'si', $newUsedBy, (int)$coupon['id']);
}

// ─── Custom Plan Flow ───────────────────────────────────────────────────────────

function handle_custom_plan(): void {
    global $db, $fromId, $msgId, $msg;

    $options = esi_get_options($db, 'BOT_CONFIG');
    if (empty($options['custom_plan_enabled'])) {
        tg_alert('❌ پلن سفارشی غیرفعال است.');
        return;
    }

    esi_set_step($db, $fromId, 'customDays');
    tg_edit($msgId, "⚙️ *پلن سفارشی*\n\nتعداد روز را وارد کنید (1 تا 365):", null, 'MarkDown');
}

function handle_custom_days(string $input): void {
    global $db, $fromId, $member, $msg;

    $days = (int)$input;
    if ($days < 1 || $days > 365) {
        tg_send('❌ عدد باید بین 1 تا 365 باشد.');
        return;
    }

    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['custom_days'] = $days;
    esi_set_temp($db, $fromId, json_encode($temp));

    esi_set_step($db, $fromId, 'customVolume');
    tg_send('📊 حجم را به گیگابایت وارد کنید (1 تا 1000)، یا 0 برای نامحدود:');
}

function handle_custom_volume(string $input): void {
    global $db, $fromId, $member, $msg;

    $volume = (int)$input;
    if ($volume < 0 || $volume > 1000) {
        tg_send('❌ عدد باید بین 0 تا 1000 باشد.');
        return;
    }

    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['custom_volume'] = $volume;

    $options      = esi_get_options($db, 'BOT_CONFIG');
    $pricePerDay  = (int)($options['custom_price_per_day'] ?? 500);
    $pricePerGb   = (int)($options['custom_price_per_gb'] ?? 1000);
    $totalPrice   = ($temp['custom_days'] * $pricePerDay) + ($volume * $pricePerGb);

    $temp['final_price'] = $totalPrice;
    $temp['pay_type']    = 'BUY_SUB';
    esi_set_temp($db, $fromId, json_encode($temp));

    esi_set_step($db, $fromId, 'customConfirm');

    $volLabel = $volume === 0 ? 'نامحدود' : $volume . ' GB';
    $text = "📋 *خلاصه پلن سفارشی*\n\n"
          . "⏱ مدت: {$temp['custom_days']} روز\n"
          . "📊 حجم: {$volLabel}\n"
          . "💰 قیمت: " . format_price($totalPrice) . "\n\n"
          . "برای ادامه *تایید* و برای لغو *لغو* بفرستید.";
    tg_send($text, null, 'MarkDown');
}

function handle_custom_confirm(string $input): void {
    global $db, $fromId, $member, $msg;

    $normalized = mb_strtolower(trim($input));
    if ($normalized !== 'تایید' && $normalized !== 'confirm') {
        tg_send('❌ لغو شد.');
        esi_set_step($db, $fromId, 'idle');
        return;
    }

    $temp   = json_decode($member['temp_data'] ?? '{}', true);
    $nodeId = $temp['node_id'] ?? null;

    if (!$nodeId) {
        tg_send('❌ سرور انتخاب نشده. لطفاً دوباره شروع کنید.');
        esi_set_step($db, $fromId, 'idle');
        return;
    }

    $refCode = 'TX' . time() . rand(1000, 9999);
    $payId = esi_create_transaction($db, [
        'ref_code'       => $refCode,
        'memo'           => 'پلن سفارشی - ' . $temp['custom_days'] . ' روز / ' . ($temp['custom_volume'] ?? 0) . ' GB',
        'gateway_ref'    => '',
        'member_id'      => $fromId,
        'tx_type'        => 'BUY_SUB',
        'package_id'     => 0,
        'volume'         => (float)($temp['custom_volume'] ?? 0),
        'duration'       => (int)($temp['custom_days'] ?? 30),
        'amount'         => (int)($temp['final_price'] ?? 0),
        'created_at'     => time(),
        'status'         => 'pending',
        'agent_purchase' => 0,
        'agent_qty'      => 1,
        'tron_amount'    => 0,
    ]);

    esi_set_step($db, $fromId, 'idle');

    $keyboard   = purchase_build_payment_keyboard($payId, (int)($temp['final_price'] ?? 0), $member);
    $keyboard[] = [['text' => '❌ لغو', 'callback_data' => 'cancelTransaction' . $payId]];

    tg_send('✅ پلن سفارشی ایجاد شد. روش پرداخت را انتخاب کنید:', json_encode([
        'inline_keyboard' => $keyboard
    ]), 'MarkDown');
}

// ─── Agent Buy (Single + Bulk) ──────────────────────────────────────────────────

function handle_agent_buy(): void {
    global $db, $fromId, $msgId, $member, $btn, $msg;

    if (empty($member['is_agent'])) {
        tg_alert('❌ دسترسی نمایندگی لازم است.');
        return;
    }

    $keyboard = [
        [['text' => $btn['agent_single'] ?? '🛒 خرید تکی', 'callback_data' => 'agentSingle']],
        [['text' => $btn['agent_bulk'] ?? '📦 خرید عمده', 'callback_data' => 'agentBulk']],
        [['text' => $btn['back'] ?? '🔙 بازگشت', 'callback_data' => 'mainMenu']],
    ];

    tg_edit($msgId, $msg['agent_buy_menu'] ?? '🏪 پنل خرید نمایندگی:', json_encode([
        'inline_keyboard' => $keyboard
    ]), 'MarkDown');
}

function handle_agent_single(): void {
    global $db, $fromId;

    $member = esi_get_member($db, $fromId);
    $temp   = json_decode($member['temp_data'] ?? '{}', true);
    $temp['agent_mode'] = 'single';
    esi_set_temp($db, $fromId, json_encode($temp));

    handle_buy_service();
}

function handle_agent_bulk(): void {
    global $db, $fromId, $msgId, $member, $msg;

    if (empty($member['is_agent'])) {
        tg_alert('❌ دسترسی نمایندگی لازم است.');
        return;
    }

    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['agent_mode'] = 'bulk';
    esi_set_temp($db, $fromId, json_encode($temp));

    esi_set_step($db, $fromId, 'agentBulkCount');
    tg_edit($msgId, $msg['enter_bulk_count'] ?? '📦 تعداد اکانت برای خرید عمده را وارد کنید (2 تا 100):', null, 'MarkDown');
}

function handle_agent_bulk_count(string $input): void {
    global $db, $fromId, $member, $msg;

    $count = (int)$input;
    if ($count < 2 || $count > 100) {
        tg_send('❌ عدد باید بین 2 تا 100 باشد.');
        return;
    }

    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['bulk_count'] = $count;
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'idle');

    // Continue to server selection
    handle_buy_service();
}

// ─── Test Account (Free Trial) ──────────────────────────────────────────────────

function handle_test_account(): void {
    global $db, $fromId, $msgId, $member, $msg;

    $options = esi_get_options($db, 'BOT_CONFIG');
    if (empty($options['test_account_enabled'])) {
        tg_alert('❌ اکانت آزمایشی غیرفعال است.', true);
        return;
    }

    // Check if user already used free trial (trial_used column on esi_members)
    if (!empty($member['trial_used'])) {
        tg_alert('❌ شما قبلاً از اکانت آزمایشی استفاده کرده‌اید.', true);
        return;
    }

    // Check minimum account age
    $minAge = (int)($options['test_min_age_days'] ?? 0);
    if ($minAge > 0) {
        $memberCreated = (int)($member['joined_at'] ?? time());
        if ((time() - $memberCreated) < ($minAge * 86400)) {
            tg_alert('❌ حساب شما باید حداقل ' . $minAge . ' روزه باشد.', true);
            return;
        }
    }

    $testDays   = (int)($options['test_days'] ?? 1);
    $testVolume = (float)($options['test_volume_gb'] ?? 1);
    $testNodeId = (int)($options['test_node_id'] ?? 0);

    if (!$testNodeId) {
        $testNode   = esi_fetch_one($db, "SELECT id FROM esi_node_info WHERE active = 1 ORDER BY id ASC LIMIT 1");
        $testNodeId = $testNode['id'] ?? 0;
    }

    if (!$testNodeId) {
        tg_alert('❌ سروری برای اکانت آزمایشی موجود نیست.');
        return;
    }

    // Find a test package on this node or use config values
    $testPkg = esi_fetch_one($db,
        "SELECT * FROM esi_packages WHERE node_id = ? AND is_test = 1 AND active = 1 LIMIT 1",
        'i', $testNodeId
    );

    $refCode = 'TEST' . time() . rand(100, 999);
    $payId = esi_create_transaction($db, [
        'ref_code'       => $refCode,
        'memo'           => 'اکانت آزمایشی رایگان',
        'gateway_ref'    => 'free_trial',
        'member_id'      => $fromId,
        'tx_type'        => 'BUY_SUB',
        'package_id'     => $testPkg ? (int)$testPkg['id'] : 0,
        'volume'         => $testPkg ? (float)$testPkg['volume'] : $testVolume,
        'duration'       => $testPkg ? (int)$testPkg['duration'] : $testDays,
        'amount'         => 0,
        'created_at'     => time(),
        'status'         => 'approved',
        'agent_purchase' => 0,
        'agent_qty'      => 1,
        'tron_amount'    => 0,
    ]);

    // Mark trial as used
    esi_update_member($db, $fromId, 'first_visit', '1');

    // Process immediately
    try {
        process_payment($payId);

        // Mark trial_used if that column/field is supported via temp
        $db->begin_transaction();
        try {
            esi_execute($db,
                "UPDATE esi_members SET first_visit = 'trial_used' WHERE tg_id = ?",
                'i', $fromId
            );
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
        }

        tg_send('✅ اکانت آزمایشی شما ایجاد شد! از بخش «سرویس‌های من» مشاهده کنید.');
    } catch (Exception $e) {
        esi_execute($db, "UPDATE esi_transactions SET status = 'failed' WHERE id = ?", 'i', $payId);
        tg_send('❌ خطا در ساخت اکانت آزمایشی: ' . $e->getMessage());
    }
}

// ─── Cancel Transaction ─────────────────────────────────────────────────────────

function purchase_handle_cancel_transaction(int $payId): void {
    global $db, $fromId, $msgId, $msg;

    $tx = esi_fetch_one($db,
        "SELECT * FROM esi_transactions WHERE id = ? AND member_id = ? AND status = 'pending'",
        'ii', $payId, $fromId
    );

    if (!$tx) {
        tg_alert('❌ تراکنش یافت نشد.');
        return;
    }

    esi_execute($db, "UPDATE esi_transactions SET status = 'cancelled' WHERE id = ?", 'i', $payId);

    // Reverse coupon usage if applicable
    $member = esi_get_member($db, $fromId);
    $temp   = json_decode($member['temp_data'] ?? '{}', true);
    if (!empty($temp['coupon_code'])) {
        purchase_reverse_coupon_usage($temp['coupon_code'], $fromId);
    }

    tg_edit($msgId, '❌ تراکنش لغو شد.', json_encode([
        'inline_keyboard' => [[['text' => '🏠 منوی اصلی', 'callback_data' => 'mainMenu']]]
    ]), 'MarkDown');
}

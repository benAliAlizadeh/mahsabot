<?php
/**
 * MahsaBot - Account Management Handler
 * Manages user subscriptions: list, view details, refresh link, renew, toggle,
 * delete, switch location, increase day/volume, QR code, admin config search.
 *
 * Schema: esi_subscriptions uses member_id (bigint), expires_at (int unix ts),
 *         status (tinyint 1=active,0=disabled), config_name (remark), config_uuid.
 *         Joined with esi_node_info (title, flag, capacity) and esi_packages.
 */

if (!defined('ESI_BOT_TOKEN')) exit('No direct access.');

// ─── My Services (List Subscriptions) ───────────────────────────────────────────

function handle_my_services(int $page = 1): void {
    global $db, $fromId, $msgId, $btn, $msg;

    $perPage = 5;
    $offset  = ($page - 1) * $perPage;

    $total = esi_count_subscriptions($db, $fromId);

    if ($total === 0) {
        $keys = json_encode(['inline_keyboard' => [
            [['text' => $btn['buy_service'] ?? '🛒 خرید سرویس', 'callback_data' => 'buyService']],
            [['text' => $btn['back'] ?? '🔙 بازگشت', 'callback_data' => 'mainMenu']],
        ]]);
        tg_edit($msgId, '📭 شما هیچ اشتراک فعالی ندارید.', $keys, 'MarkDown');
        return;
    }

    $subs = esi_get_subscriptions($db, $fromId, $perPage, $offset);

    $keyboard = [];
    foreach ($subs as $sub) {
        $statusIcon = ((int)$sub['status'] === 1) ? '🟢' : '🔴';
        $expiry = (int)$sub['expires_at'];
        if ($expiry > 0 && $expiry < time()) $statusIcon = '⏰';

        $nodeTitle = $sub['node_title'] ?? '-';
        $label = "{$statusIcon} #{$sub['id']} | {$nodeTitle} | {$sub['config_name']}";
        $keyboard[] = [['text' => $label, 'callback_data' => 'orderDetails' . $sub['id']]];
    }

    // Pagination
    $totalPages = (int)ceil($total / $perPage);
    if ($totalPages > 1) {
        $paginationRow = [];
        if ($page > 1) $paginationRow[] = ['text' => '⬅️', 'callback_data' => 'myServicesPage' . ($page - 1)];
        $paginationRow[] = ['text' => "{$page}/{$totalPages}", 'callback_data' => 'noop'];
        if ($page < $totalPages) $paginationRow[] = ['text' => '➡️', 'callback_data' => 'myServicesPage' . ($page + 1)];
        $keyboard[] = $paginationRow;
    }

    $keyboard[] = [['text' => $btn['back'] ?? '🔙 بازگشت', 'callback_data' => 'mainMenu']];

    $text = "📋 *سرویس‌های من* ({$total} مورد)\n\nیکی را انتخاب کنید:";
    tg_edit($msgId, $text, json_encode(['inline_keyboard' => $keyboard]), 'MarkDown');
}

// ─── Order Details ──────────────────────────────────────────────────────────────

function handle_order_details(int $subId): void {
    global $db, $fromId, $msgId, $isAdmin, $btn, $msg;

    $sub = get_user_subscription($subId, $fromId, $isAdmin);
    if (!$sub) return;

    $nodeInfo = esi_fetch_one($db, "SELECT * FROM esi_node_info WHERE id = ?", 'i', $sub['node_id']);
    $pkg      = esi_fetch_one($db, "SELECT * FROM esi_packages WHERE id = ?", 'i', $sub['package_id']);

    $statusText = ((int)$sub['status'] === 1) ? '🟢 فعال' : '🔴 غیرفعال';
    $expiry     = (int)$sub['expires_at'];
    if ($expiry > 0 && $expiry < time()) $statusText = '⏰ منقضی';

    $daysLeft = ($expiry > time()) ? max(0, (int)floor(($expiry - time()) / 86400)) : 0;
    $volumeStr = ($pkg && (float)$pkg['volume'] > 0) ? format_traffic((float)$pkg['volume']) : 'نامحدود';
    $expiryStr = ($expiry > 0) ? jdate('Y/m/d', $expiry) : '-';

    $text = "📦 *اشتراک #{$sub['id']}*\n\n"
          . "{$statusText}\n"
          . "🌍 سرور: " . ($nodeInfo['flag'] ?? '') . ' ' . ($nodeInfo['title'] ?? '-') . "\n"
          . "👤 کانفیگ: `{$sub['config_name']}`\n"
          . "📊 حجم: {$volumeStr}\n"
          . "⏱ مانده: {$daysLeft} روز\n"
          . "📅 انقضا: {$expiryStr}\n"
          . "📅 ساخت: " . jdate('Y/m/d', (int)$sub['created_at']);

    $keyboard = [
        [
            ['text' => '🔗 دریافت لینک', 'callback_data' => 'refreshConfig' . $subId],
            ['text' => '📱 QR Code', 'callback_data' => 'showQR' . $subId],
        ],
        [
            ['text' => '🔄 تمدید', 'callback_data' => 'renewService' . $subId],
            ['text' => '🔑 لینک جدید', 'callback_data' => 'renewLink' . $subId],
        ],
        [
            ['text' => '⏱ + روز', 'callback_data' => 'increaseDay' . $subId],
            ['text' => '📊 + حجم', 'callback_data' => 'increaseVolume' . $subId],
        ],
        [
            ['text' => '🌍 تغییر سرور', 'callback_data' => 'switchLocation' . $subId],
        ],
    ];

    // Enable/Disable toggle
    if ((int)$sub['status'] === 1) {
        $keyboard[] = [['text' => '🔴 غیرفعال کردن', 'callback_data' => 'disableConfig' . $subId]];
    } else {
        $keyboard[] = [['text' => '🟢 فعال کردن', 'callback_data' => 'enableConfig' . $subId]];
    }

    $keyboard[] = [['text' => '🗑 حذف', 'callback_data' => 'deleteMyConfig' . $subId]];
    $keyboard[] = [['text' => $btn['back'] ?? '🔙 بازگشت', 'callback_data' => 'myServices']];

    tg_edit($msgId, $text, json_encode(['inline_keyboard' => $keyboard]), 'MarkDown');
}

// ─── Refresh Config (Get Connection Link) ───────────────────────────────────────

function handle_refresh_config(int $subId): void {
    global $db, $fromId, $isAdmin;

    $sub = get_user_subscription($subId, $fromId, $isAdmin);
    if (!$sub) return;

    $nodeConfig = esi_fetch_one($db, "SELECT * FROM esi_node_config WHERE id = ?", 'i', $sub['node_id']);
    if (!$nodeConfig) {
        tg_alert('❌ سرور یافت نشد.');
        return;
    }

    $panelType = $nodeConfig['panel_type'] ?? 'sanaei';
    $link = '';

    if ($panelType === 'marzban') {
        $link = marzban_get_user_link($db, $nodeConfig, $sub['config_name']);
    } else {
        $result = xui_get_connection_link($db, $nodeConfig, $sub);
        $link = $result['link'] ?? '';
    }

    if (empty($link)) {
        // Fallback to stored link
        $link = $sub['connect_link'] ?? '';
    }

    if (empty($link)) {
        tg_alert('❌ لینک اتصال یافت نشد.');
        return;
    }

    tg_send("🔗 *لینک اتصال*\n\nاشتراک #{$subId}\n\n`{$link}`\n\n📋 لینک را کپی و در اپ VPN وارد کنید.", null, 'MarkDown');
}

// ─── Renew Link (New UUID) ──────────────────────────────────────────────────────

function handle_renew_link(int $subId): void {
    global $db, $fromId, $isAdmin;

    $sub = get_user_subscription($subId, $fromId, $isAdmin);
    if (!$sub) return;

    $nodeConfig = esi_fetch_one($db, "SELECT * FROM esi_node_config WHERE id = ?", 'i', $sub['node_id']);
    if (!$nodeConfig) {
        tg_alert('❌ سرور یافت نشد.');
        return;
    }

    $panelType = $nodeConfig['panel_type'] ?? 'sanaei';

    if ($panelType === 'marzban') {
        // Marzban: revoke subscription link (generates new token)
        $newSubLink = marzban_revoke_subscription($db, $nodeConfig, $sub['config_name']);
        if (empty($newSubLink)) {
            tg_alert('❌ خطا در بازسازی لینک.');
            return;
        }
        esi_execute($db, "UPDATE esi_subscriptions SET connect_link = ? WHERE id = ?", 'si', $newSubLink, $subId);
        tg_send("🔑 *لینک جدید ساخته شد!*\n\nاشتراک #{$subId}\n\n`{$newSubLink}`\n\n⚠️ لینک قبلی از کار افتاده.", null, 'MarkDown');
    } else {
        // XUI: renew UUID
        $result = xui_renew_uuid($db, $nodeConfig, $sub);
        if (empty($result['success'])) {
            tg_alert('❌ خطا در تغییر UUID: ' . ($result['error'] ?? ''));
            return;
        }

        $newUuid = $result['uuid'] ?? generate_uuid();
        esi_execute($db, "UPDATE esi_subscriptions SET config_uuid = ? WHERE id = ?", 'si', $newUuid, $subId);

        // Get updated link
        $sub['config_uuid'] = $newUuid;
        $linkResult = xui_get_connection_link($db, $nodeConfig, $sub);
        $newLink = $linkResult['link'] ?? '';

        tg_send("🔑 *لینک جدید ساخته شد!*\n\nاشتراک #{$subId}\n\n`{$newLink}`\n\n⚠️ لینک قبلی از کار افتاده.", null, 'MarkDown');
    }
}

// ─── Enable / Disable Config ────────────────────────────────────────────────────

function handle_enable_config(int $subId): void {
    global $db, $fromId, $isAdmin;

    $sub = get_user_subscription($subId, $fromId, $isAdmin);
    if (!$sub) return;

    $nodeConfig = esi_fetch_one($db, "SELECT * FROM esi_node_config WHERE id = ?", 'i', $sub['node_id']);
    if (!$nodeConfig) { tg_alert('❌ سرور یافت نشد.'); return; }

    $panelType = $nodeConfig['panel_type'] ?? 'sanaei';

    if ($panelType === 'marzban') {
        $ok = marzban_change_state($db, $nodeConfig, $sub['config_name'], true);
    } else {
        $result = xui_change_state($db, $nodeConfig, $sub, true);
        $ok = !empty($result['success']);
    }

    if (!$ok) { tg_alert('❌ خطا در فعال‌سازی.'); return; }

    esi_execute($db, "UPDATE esi_subscriptions SET status = 1 WHERE id = ?", 'i', $subId);
    tg_alert('✅ اشتراک فعال شد.');
    handle_order_details($subId);
}

function handle_disable_config(int $subId): void {
    global $db, $fromId, $isAdmin;

    $sub = get_user_subscription($subId, $fromId, $isAdmin);
    if (!$sub) return;

    $nodeConfig = esi_fetch_one($db, "SELECT * FROM esi_node_config WHERE id = ?", 'i', $sub['node_id']);
    if (!$nodeConfig) { tg_alert('❌ سرور یافت نشد.'); return; }

    $panelType = $nodeConfig['panel_type'] ?? 'sanaei';

    if ($panelType === 'marzban') {
        $ok = marzban_change_state($db, $nodeConfig, $sub['config_name'], false);
    } else {
        $result = xui_change_state($db, $nodeConfig, $sub, false);
        $ok = !empty($result['success']);
    }

    if (!$ok) { tg_alert('❌ خطا در غیرفعال‌سازی.'); return; }

    esi_execute($db, "UPDATE esi_subscriptions SET status = 0 WHERE id = ?", 'i', $subId);
    tg_alert('✅ اشتراک غیرفعال شد.');
    handle_order_details($subId);
}

// ─── Delete Config ──────────────────────────────────────────────────────────────

function handle_delete_my_config(int $subId): void {
    global $db, $fromId, $msgId, $isAdmin, $btn;

    $sub = get_user_subscription($subId, $fromId, $isAdmin);
    if (!$sub) return;

    $keyboard = json_encode(['inline_keyboard' => [
        [
            ['text' => '✅ بله حذف شود', 'callback_data' => 'confirmDeleteConfig' . $subId],
            ['text' => '❌ نه نگه دار', 'callback_data' => 'orderDetails' . $subId],
        ]
    ]]);

    tg_edit($msgId,
        "⚠️ *حذف اشتراک #{$subId}؟*\n\nکانفیگ از سرور حذف خواهد شد. این عمل قابل بازگشت نیست.",
        $keyboard, 'MarkDown'
    );
}

function handle_confirm_delete_config(int $subId): void {
    global $db, $fromId, $msgId, $isAdmin;

    $sub = get_user_subscription($subId, $fromId, $isAdmin);
    if (!$sub) return;

    $nodeConfig = esi_fetch_one($db, "SELECT * FROM esi_node_config WHERE id = ?", 'i', $sub['node_id']);
    $nodeInfo   = esi_fetch_one($db, "SELECT * FROM esi_node_info WHERE id = ?", 'i', $sub['node_id']);

    // Delete from panel
    if ($nodeConfig) {
        try {
            delete_from_panel($nodeConfig, $sub);
        } catch (Exception $e) {
            error_log("MahsaBot: Panel delete failed for sub #{$subId}: " . $e->getMessage());
        }
    }

    // Restore capacity
    if ($nodeInfo && (int)$nodeInfo['capacity'] > 0) {
        esi_execute($db, "UPDATE esi_node_info SET capacity = capacity + 1 WHERE id = ?", 'i', $sub['node_id']);
    }

    esi_execute($db, "UPDATE esi_subscriptions SET status = 0 WHERE id = ?", 'i', $subId);

    $keys = json_encode(['inline_keyboard' => [
        [['text' => '📋 سرویس‌های من', 'callback_data' => 'myServices']]
    ]]);
    tg_edit($msgId, '✅ اشتراک حذف شد.', $keys, 'MarkDown');
}

// ─── Renew Service ──────────────────────────────────────────────────────────────

function handle_renew_service(int $subId): void {
    global $db, $fromId, $msgId, $member, $isAdmin, $btn;

    $sub = get_user_subscription($subId, $fromId, $isAdmin);
    if (!$sub) return;

    // Get packages for same node group
    $pkg = esi_fetch_one($db, "SELECT group_id FROM esi_packages WHERE id = ?", 'i', $sub['package_id']);
    $groupId = $pkg['group_id'] ?? 0;

    $packages = [];
    if ($groupId > 0) {
        $packages = esi_fetch_all($db,
            "SELECT * FROM esi_packages WHERE group_id = ? AND active = 1 ORDER BY sort_order, price",
            'i', $groupId
        );
    }
    if (empty($packages)) {
        $packages = esi_fetch_all($db,
            "SELECT * FROM esi_packages WHERE node_id = ? AND active = 1 ORDER BY sort_order, price",
            'i', $sub['node_id']
        );
    }

    if (empty($packages)) {
        tg_alert('❌ پلن تمدید موجود نیست.');
        return;
    }

    $keyboard = [];
    foreach ($packages as $p) {
        $label = $p['title'] . ' | ' . format_traffic((float)$p['volume']) . ' | '
               . $p['duration'] . 'ر | ' . format_price((int)$p['price']);
        $keyboard[] = [['text' => $label, 'callback_data' => 'renewPkg' . $p['id'] . '_' . $subId]];
    }
    $keyboard[] = [['text' => $btn['back'] ?? '🔙 بازگشت', 'callback_data' => 'orderDetails' . $subId]];

    tg_edit($msgId, "🔄 *تمدید اشتراک #{$subId}*\n\nیک پلن انتخاب کنید:",
        json_encode(['inline_keyboard' => $keyboard]), 'MarkDown');
}

function handle_renew_with_package(int $packageId, int $subId): void {
    global $db, $fromId, $msgId, $member;

    $pkg = esi_fetch_one($db, "SELECT * FROM esi_packages WHERE id = ? AND active = 1", 'i', $packageId);
    if (!$pkg) { tg_alert('❌ پلن یافت نشد.'); return; }

    // Create transaction
    $payId = esi_create_transaction($db, [
        'ref_code'       => 'RNW' . time(),
        'memo'           => 'renew_sub:' . $subId,
        'gateway_ref'    => '',
        'member_id'      => $fromId,
        'tx_type'        => 'RENEW_ACCOUNT',
        'package_id'     => $packageId,
        'volume'         => (float)$pkg['volume'],
        'duration'       => (int)$pkg['duration'],
        'amount'         => (int)$pkg['price'],
        'created_at'     => time(),
        'status'         => 'pending',
        'agent_purchase' => 0,
        'agent_qty'      => 0,
        'tron_amount'    => 0,
    ]);

    $rows = build_payment_keyboard($payId, (int)$pkg['price'], $member);
    $rows[] = [['text' => '❌ انصراف', 'callback_data' => 'cancelTx' . $payId]];

    tg_edit($msgId,
        "🔄 *تمدید اشتراک #{$subId}*\n\n📦 {$pkg['title']}\n💰 " . format_price((int)$pkg['price']) . "\n\nروش پرداخت را انتخاب کنید:",
        json_encode(['inline_keyboard' => $rows]), 'MarkDown'
    );
}

// ─── Switch Location ────────────────────────────────────────────────────────────

function handle_switch_location(int $subId): void {
    global $db, $fromId, $msgId, $isAdmin, $btn;

    $sub = get_user_subscription($subId, $fromId, $isAdmin);
    if (!$sub) return;

    $options = esi_get_options($db, 'BOT_CONFIG');
    if (empty($options['switch_location_enabled'])) {
        tg_alert('❌ تغییر سرور غیرفعال است.');
        return;
    }

    $switchFee = (int)($options['switch_location_fee'] ?? 0);

    $nodes = esi_fetch_all($db,
        "SELECT * FROM esi_node_info WHERE active = 1 AND id != ? ORDER BY id",
        'i', $sub['node_id']
    );

    if (empty($nodes)) { tg_alert('❌ سرور دیگری موجود نیست.'); return; }

    $keyboard = [];
    foreach ($nodes as $node) {
        $label = ($node['flag'] ?? '🌐') . ' ' . $node['title'];
        if ((int)$node['capacity'] <= 0 && (int)$node['capacity'] !== 0) $label .= ' (پر)';
        $keyboard[] = [['text' => $label, 'callback_data' => 'confirmSwitch' . $subId . '_' . $node['id']]];
    }
    $keyboard[] = [['text' => $btn['back'] ?? '🔙 بازگشت', 'callback_data' => 'orderDetails' . $subId]];

    $feeText = $switchFee > 0 ? "\n💰 هزینه: " . format_price($switchFee) : "\n✅ رایگان";
    tg_edit($msgId, "🌍 *تغییر سرور اشتراک #{$subId}*{$feeText}\n\nسرور جدید را انتخاب کنید:",
        json_encode(['inline_keyboard' => $keyboard]), 'MarkDown');
}

function handle_confirm_switch(int $subId, int $newNodeId): void {
    global $db, $fromId, $msgId, $member, $isAdmin;

    $sub = get_user_subscription($subId, $fromId, $isAdmin);
    if (!$sub) return;

    $newNodeInfo   = esi_fetch_one($db, "SELECT * FROM esi_node_info WHERE id = ? AND active = 1", 'i', $newNodeId);
    $newNodeConfig = esi_fetch_one($db, "SELECT * FROM esi_node_config WHERE id = ?", 'i', $newNodeId);
    if (!$newNodeInfo || !$newNodeConfig) { tg_alert('❌ سرور یافت نشد.'); return; }

    if ((int)$newNodeInfo['capacity'] <= 0 && (int)$newNodeInfo['capacity'] !== 0) {
        tg_alert('❌ ظرفیت سرور پر است.');
        return;
    }

    $options   = esi_get_options($db, 'BOT_CONFIG');
    $switchFee = (int)($options['switch_location_fee'] ?? 0);

    if ($switchFee > 0) {
        $balance = (int)($member['balance'] ?? 0);
        if ($balance < $switchFee) {
            tg_alert('❌ موجودی ناکافی. نیاز به ' . format_price($switchFee));
            return;
        }
        esi_execute($db, "UPDATE esi_members SET balance = balance - ? WHERE tg_id = ?", 'ii', $switchFee, $fromId);
    }

    $oldNodeConfig = esi_fetch_one($db, "SELECT * FROM esi_node_config WHERE id = ?", 'i', $sub['node_id']);
    $oldNodeInfo   = esi_fetch_one($db, "SELECT * FROM esi_node_info WHERE id = ?", 'i', $sub['node_id']);
    $pkg           = esi_fetch_one($db, "SELECT * FROM esi_packages WHERE id = ?", 'i', $sub['package_id']);

    try {
        // Delete from old panel
        if ($oldNodeConfig) delete_from_panel($oldNodeConfig, $sub);

        // Create on new panel
        $days    = max(1, (int)floor(((int)$sub['expires_at'] - time()) / 86400));
        $volume  = $pkg ? (float)$pkg['volume'] : 0;
        $newPanelType = $newNodeConfig['panel_type'] ?? 'sanaei';

        if ($newPanelType === 'marzban') {
            $result = marzban_add_user_account($db, $pkg ?? [], $newNodeConfig, $sub['config_name'], $days, $volume);
        } else {
            $result = xui_add_user_account($db, $pkg ?? [], $newNodeConfig, $sub['config_name'], $sub['config_uuid'], $days, $volume);
        }

        if (empty($result['success'])) throw new Exception($result['error'] ?? 'خطای پنل');

        // Update DB
        esi_execute($db, "UPDATE esi_subscriptions SET node_id = ? WHERE id = ?", 'ii', $newNodeId, $subId);

        // Adjust capacities
        if ($oldNodeInfo && (int)$oldNodeInfo['capacity'] > 0) {
            esi_execute($db, "UPDATE esi_node_info SET capacity = capacity + 1 WHERE id = ?", 'i', $sub['node_id']);
        }
        if ((int)$newNodeInfo['capacity'] > 0) {
            esi_execute($db, "UPDATE esi_node_info SET capacity = GREATEST(0, capacity - 1) WHERE id = ?", 'i', $newNodeId);
        }

        $keys = json_encode(['inline_keyboard' => [
            [['text' => '📦 مشاهده جزئیات', 'callback_data' => 'orderDetails' . $subId]]
        ]]);
        tg_edit($msgId, "✅ سرور به " . ($newNodeInfo['flag'] ?? '') . " {$newNodeInfo['title']} تغییر کرد!",
            $keys, 'MarkDown');

    } catch (Exception $e) {
        // Refund fee on failure
        if ($switchFee > 0) {
            esi_execute($db, "UPDATE esi_members SET balance = balance + ? WHERE tg_id = ?", 'ii', $switchFee, $fromId);
        }
        tg_alert('❌ تغییر سرور ناموفق: ' . $e->getMessage());
    }
}

// ─── Increase Day ───────────────────────────────────────────────────────────────

function handle_increase_day(int $subId): void {
    global $db, $fromId, $msgId, $isAdmin, $btn;

    $sub = get_user_subscription($subId, $fromId, $isAdmin);
    if (!$sub) return;

    $addons = esi_fetch_all($db, "SELECT * FROM esi_addons_day WHERE status = 1 ORDER BY days ASC");
    if (empty($addons)) { tg_alert('❌ افزونه روز موجود نیست.'); return; }

    $keyboard = [];
    foreach ($addons as $addon) {
        $label = $addon['days'] . ' روز | ' . format_price((int)$addon['price']);
        $keyboard[] = [['text' => $label, 'callback_data' => 'buyAddonDay' . $addon['id'] . '_' . $subId]];
    }
    $keyboard[] = [['text' => $btn['back'] ?? '🔙 بازگشت', 'callback_data' => 'orderDetails' . $subId]];

    tg_edit($msgId, "⏱ *افزایش روز اشتراک #{$subId}*\n\nیک افزونه انتخاب کنید:",
        json_encode(['inline_keyboard' => $keyboard]), 'MarkDown');
}

function handle_buy_addon_day(int $addonId, int $subId): void {
    global $db, $fromId, $msgId, $member;

    $addon = esi_fetch_one($db, "SELECT * FROM esi_addons_day WHERE id = ? AND status = 1", 'i', $addonId);
    if (!$addon) { tg_alert('❌ افزونه یافت نشد.'); return; }

    $payId = esi_create_transaction($db, [
        'ref_code'       => 'ADAY' . time(),
        'memo'           => 'addon_sub:' . $subId,
        'gateway_ref'    => '',
        'member_id'      => $fromId,
        'tx_type'        => 'INCREASE_DAY',
        'package_id'     => 0,
        'volume'         => 0,
        'duration'       => (int)$addon['days'],
        'amount'         => (int)$addon['price'],
        'created_at'     => time(),
        'status'         => 'pending',
        'agent_purchase' => 0,
        'agent_qty'      => 0,
        'tron_amount'    => 0,
    ]);

    // Record addon order
    esi_execute($db,
        "INSERT INTO esi_addon_orders (member_id, subscription_id, addon_type, addon_id, transaction_id, created_at)
         VALUES (?, ?, 'day', ?, ?, ?)",
        'iiiis', $fromId, $subId, $addonId, $payId, time()
    );

    $rows = build_payment_keyboard($payId, (int)$addon['price'], $member);
    $rows[] = [['text' => '❌ انصراف', 'callback_data' => 'cancelTx' . $payId]];

    tg_edit($msgId, "⏱ *افزایش {$addon['days']} روز*\n💰 " . format_price((int)$addon['price']) . "\n\nروش پرداخت:",
        json_encode(['inline_keyboard' => $rows]), 'MarkDown');
}

// ─── Increase Volume ────────────────────────────────────────────────────────────

function handle_increase_volume(int $subId): void {
    global $db, $fromId, $msgId, $isAdmin, $btn;

    $sub = get_user_subscription($subId, $fromId, $isAdmin);
    if (!$sub) return;

    $addons = esi_fetch_all($db, "SELECT * FROM esi_addons_volume WHERE status = 1 ORDER BY volume_gb ASC");
    if (empty($addons)) { tg_alert('❌ افزونه حجم موجود نیست.'); return; }

    $keyboard = [];
    foreach ($addons as $addon) {
        $label = format_traffic((float)$addon['volume_gb']) . ' | ' . format_price((int)$addon['price']);
        $keyboard[] = [['text' => $label, 'callback_data' => 'buyAddonVol' . $addon['id'] . '_' . $subId]];
    }
    $keyboard[] = [['text' => $btn['back'] ?? '🔙 بازگشت', 'callback_data' => 'orderDetails' . $subId]];

    tg_edit($msgId, "📊 *افزایش حجم اشتراک #{$subId}*\n\nیک افزونه انتخاب کنید:",
        json_encode(['inline_keyboard' => $keyboard]), 'MarkDown');
}

function handle_buy_addon_volume(int $addonId, int $subId): void {
    global $db, $fromId, $msgId, $member;

    $addon = esi_fetch_one($db, "SELECT * FROM esi_addons_volume WHERE id = ? AND status = 1", 'i', $addonId);
    if (!$addon) { tg_alert('❌ افزونه یافت نشد.'); return; }

    $payId = esi_create_transaction($db, [
        'ref_code'       => 'AVOL' . time(),
        'memo'           => 'addon_sub:' . $subId,
        'gateway_ref'    => '',
        'member_id'      => $fromId,
        'tx_type'        => 'INCREASE_VOLUME',
        'package_id'     => 0,
        'volume'         => (float)$addon['volume_gb'],
        'duration'       => 0,
        'amount'         => (int)$addon['price'],
        'created_at'     => time(),
        'status'         => 'pending',
        'agent_purchase' => 0,
        'agent_qty'      => 0,
        'tron_amount'    => 0,
    ]);

    esi_execute($db,
        "INSERT INTO esi_addon_orders (member_id, subscription_id, addon_type, addon_id, transaction_id, created_at)
         VALUES (?, ?, 'volume', ?, ?, ?)",
        'iiiis', $fromId, $subId, $addonId, $payId, time()
    );

    $rows = build_payment_keyboard($payId, (int)$addon['price'], $member);
    $rows[] = [['text' => '❌ انصراف', 'callback_data' => 'cancelTx' . $payId]];

    tg_edit($msgId, "📊 *افزایش " . format_traffic((float)$addon['volume_gb']) . "*\n💰 " . format_price((int)$addon['price']) . "\n\nروش پرداخت:",
        json_encode(['inline_keyboard' => $rows]), 'MarkDown');
}

// ─── QR Code ────────────────────────────────────────────────────────────────────

function handle_show_qr(int $subId): void {
    global $db, $fromId, $isAdmin;

    $sub = get_user_subscription($subId, $fromId, $isAdmin);
    if (!$sub) return;

    $link = $sub['connect_link'] ?? '';
    if (empty($link)) {
        $link = build_subscription_link_for_user($db, $subId);
    }

    if (empty($link)) {
        tg_alert('❌ لینک اتصال یافت نشد.');
        return;
    }

    $qrPath = generate_qr_code_for_sub($link, $subId);

    if ($qrPath && file_exists($qrPath)) {
        tg_photo(new \CURLFile($qrPath), "📱 QR Code اشتراک #{$subId}\n\nبا اپ VPN اسکن کنید.", null, 'MarkDown');
    } else {
        tg_alert('❌ ساخت QR Code ناموفق.');
    }
}

// ─── Admin: Search User Config ──────────────────────────────────────────────────

function handle_search_user_config(): void {
    global $db, $fromId, $msgId, $isAdmin;

    if (!$isAdmin) { tg_alert('❌ دسترسی ادمین لازم است.'); return; }

    esi_set_step($db, $fromId, 'searchUserConfig');
    tg_edit($msgId, '🔍 آیدی کاربر، یوزرنیم یا نام کانفیگ را وارد کنید:', null, 'MarkDown');
}

function handle_search_user_config_input(string $query): void {
    global $db, $fromId, $isAdmin;

    if (!$isAdmin) return;

    $query = trim($query);
    $numQuery = is_numeric($query) ? (int)$query : 0;

    $subs = esi_fetch_all($db,
        "SELECT s.*, n.title as node_title, m.display_name
         FROM esi_subscriptions s
         LEFT JOIN esi_node_info n ON n.id = s.node_id
         LEFT JOIN esi_members m ON m.tg_id = s.member_id
         WHERE s.member_id = ? OR s.config_name LIKE ? OR m.tg_username LIKE ?
         ORDER BY s.id DESC LIMIT 20",
        'iss', $numQuery, "%{$query}%", "%{$query}%"
    );

    esi_set_step($db, $fromId, 'idle');

    if (empty($subs)) {
        tg_send('❌ نتیجه‌ای یافت نشد.');
        return;
    }

    $keyboard = [];
    foreach ($subs as $sub) {
        $statusIcon = ((int)$sub['status'] === 1) ? '🟢' : '🔴';
        $label = "{$statusIcon} #{$sub['id']} | " . ($sub['display_name'] ?? '-') . " | {$sub['config_name']}";
        $keyboard[] = [['text' => $label, 'callback_data' => 'orderDetails' . $sub['id']]];
    }

    tg_send('🔍 نتایج جستجو (' . count($subs) . '):', json_encode(['inline_keyboard' => $keyboard]), 'MarkDown');
}

// ─── Helper: Get Subscription with Auth Check ───────────────────────────────────

function get_user_subscription(int $subId, int $userId, bool $isAdmin): ?array {
    global $db;

    if ($isAdmin) {
        $sub = esi_fetch_one($db, "SELECT * FROM esi_subscriptions WHERE id = ?", 'i', $subId);
    } else {
        $sub = esi_fetch_one($db, "SELECT * FROM esi_subscriptions WHERE id = ? AND member_id = ?", 'ii', $subId, $userId);
    }

    if (!$sub) {
        tg_alert('❌ اشتراک یافت نشد.');
        return null;
    }

    return $sub;
}

/**
 * Delete account from panel.
 */
function delete_from_panel(array $nodeConfig, array $sub): void {
    $panelType = $nodeConfig['panel_type'] ?? 'sanaei';
    global $db;

    if ($panelType === 'marzban') {
        marzban_delete_user_account($db, $nodeConfig, $sub['config_name']);
        return;
    }

    xui_delete_account($db, $nodeConfig, $sub);
}

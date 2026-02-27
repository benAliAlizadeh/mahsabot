<?php
/**
 * MahsaBot - Package / Plan Management Handler
 * Admin-only: CRUD for packages (esi_packages)
 *
 * @package MahsaBot\Handlers
 */

if (!$isAdmin) return;

// ── Plan List for a Group ───────────────────────────────────────
if (preg_match('/^planList(\d+)$/', $data, $m)) {
    $gid = (int) $m[1];
    $group = esi_fetch_one($db, "SELECT * FROM `esi_groups` WHERE `id` = ?", 'i', $gid);
    $plans = esi_fetch_all($db,
        "SELECT * FROM `esi_packages` WHERE `group_id` = ? ORDER BY `sort_order` ASC, `id` ASC", 'i', $gid
    );
    $keys = [];
    foreach ($plans as $p) {
        $status = $p['active'] ? '🟢' : '🔴';
        $keys[] = [['text' => "{$status} {$p['title']} - " . format_price($p['price']) . ' T', 'callback_data' => 'viewPlan' . $p['id']]];
    }
    $keys[] = [['text' => '➕ افزودن پلن', 'callback_data' => 'addPlan' . $gid]];
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'viewCategory' . $gid]];
    $title = $group ? "📦 پلن‌های گروه: {$group['title']}" : '📦 پلن‌ها';
    tg_edit($msgId, $title, json_encode(['inline_keyboard' => $keys]));
}

// ── Package Settings (all groups) ───────────────────────────────
if ($data === 'packageSettings') {
    $groups = esi_fetch_all($db,
        "SELECT g.*, ni.flag FROM `esi_groups` g
         LEFT JOIN `esi_node_info` ni ON g.`node_id` = ni.`id`
         WHERE g.`active` = 1 ORDER BY g.`sort_order` ASC"
    );
    $keys = [];
    foreach ($groups as $g) {
        $flag = $g['flag'] ?? '🌐';
        $cnt = esi_fetch_one($db, "SELECT COUNT(*) as cnt FROM `esi_packages` WHERE `group_id` = ?", 'i', $g['id'])['cnt'] ?? 0;
        $keys[] = [['text' => "{$flag} {$g['title']} ({$cnt} پلن)", 'callback_data' => 'planList' . $g['id']]];
    }
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
    tg_edit($msgId, '📦 مدیریت پلن‌ها - گروه را انتخاب کنید:', json_encode(['inline_keyboard' => $keys]));
}

// ── Add Plan: Start → Title ─────────────────────────────────────
if (preg_match('/^addPlan(\d+)$/', $data, $m)) {
    $gid = (int) $m[1];
    $group = esi_fetch_one($db, "SELECT * FROM `esi_groups` WHERE `id` = ?", 'i', $gid);
    if (!$group) {
        tg_alert('❌ گروه یافت نشد.');
    } else {
        $temp = ['group_id' => $gid, 'node_id' => (int) $group['node_id']];
        esi_set_temp($db, $fromId, json_encode($temp));
        esi_set_step($db, $fromId, 'addPlanTitle');
        tg_delete();
        tg_send('📝 عنوان پلن جدید را وارد کنید:', $cancelKeyboard);
    }
}

// ── Add Plan: Title → Protocol ──────────────────────────────────
if ($step === 'addPlanTitle' && $text !== $btn['cancel']) {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['title'] = $text;
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'addPlanProtocol');
    $keys = json_encode(['inline_keyboard' => [
        [
            ['text' => 'VLESS', 'callback_data' => 'planProto_vless'],
            ['text' => 'VMess', 'callback_data' => 'planProto_vmess'],
            ['text' => 'Trojan', 'callback_data' => 'planProto_trojan'],
        ],
    ]]);
    tg_send('⚙️ پروتکل را انتخاب کنید:', $keys);
}

// ── Add Plan: Protocol → Volume ─────────────────────────────────
if (preg_match('/^planProto_(vless|vmess|trojan)$/', $data, $m) && $step === 'addPlanProtocol') {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['protocol'] = $m[1];
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'addPlanVolume');
    tg_delete();
    tg_send('📊 حجم ترافیک را وارد کنید (گیگابایت، 0 = نامحدود):', $cancelKeyboard);
}

// ── Add Plan: Volume → Duration ─────────────────────────────────
if ($step === 'addPlanVolume' && $text !== $btn['cancel']) {
    if (!is_numeric($text)) {
        tg_send($msg['number_only'] ?? '❌ لطفاً عدد وارد کنید.');
    } else {
        $temp = json_decode($member['temp_data'] ?? '{}', true);
        $temp['volume'] = (float) $text;
        esi_set_temp($db, $fromId, json_encode($temp));
        esi_set_step($db, $fromId, 'addPlanDuration');
        tg_send('⏱ مدت زمان سرویس را وارد کنید (روز، 0 = نامحدود):');
    }
}

// ── Add Plan: Duration → Price ──────────────────────────────────
if ($step === 'addPlanDuration' && $text !== $btn['cancel']) {
    if (!is_numeric($text)) {
        tg_send($msg['number_only'] ?? '❌ لطفاً عدد وارد کنید.');
    } else {
        $temp = json_decode($member['temp_data'] ?? '{}', true);
        $temp['duration'] = (int) $text;
        esi_set_temp($db, $fromId, json_encode($temp));
        esi_set_step($db, $fromId, 'addPlanPrice');
        tg_send('💰 قیمت پلن را وارد کنید (تومان):');
    }
}

// ── Add Plan: Price → Net Type ──────────────────────────────────
if ($step === 'addPlanPrice' && $text !== $btn['cancel']) {
    if (!is_numeric($text)) {
        tg_send($msg['number_only'] ?? '❌ لطفاً عدد وارد کنید.');
    } else {
        $temp = json_decode($member['temp_data'] ?? '{}', true);
        $temp['price'] = (int) $text;
        esi_set_temp($db, $fromId, json_encode($temp));
        esi_set_step($db, $fromId, 'addPlanNet');
        $keys = json_encode(['inline_keyboard' => [
            [
                ['text' => 'WS', 'callback_data' => 'planNet_ws'],
                ['text' => 'TCP', 'callback_data' => 'planNet_tcp'],
            ],
            [
                ['text' => 'gRPC', 'callback_data' => 'planNet_grpc'],
                ['text' => 'KCP', 'callback_data' => 'planNet_kcp'],
            ],
        ]]);
        tg_send('🌐 نوع شبکه (Network) را انتخاب کنید:', $keys);
    }
}

// ── Add Plan: Net Type → Security ───────────────────────────────
if (preg_match('/^planNet_(ws|tcp|grpc|kcp)$/', $data, $m) && $step === 'addPlanNet') {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['net_type'] = $m[1];
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'addPlanSecurity');
    $keys = json_encode(['inline_keyboard' => [
        [
            ['text' => 'TLS', 'callback_data' => 'planSec_tls'],
            ['text' => 'XTLS', 'callback_data' => 'planSec_xtls'],
        ],
        [
            ['text' => 'Reality', 'callback_data' => 'planSec_reality'],
            ['text' => 'None', 'callback_data' => 'planSec_none'],
        ],
    ]]);
    tg_send('🔒 نوع امنیت (Security) را انتخاب کنید:', $keys);
}

// ── Add Plan: Security → Description ────────────────────────────
if (preg_match('/^planSec_(tls|xtls|reality|none)$/', $data, $m) && $step === 'addPlanSecurity') {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['security'] = $m[1];
    esi_set_temp($db, $fromId, json_encode($temp));

    // Check if this is a Marzban node
    $nc = esi_fetch_one($db, "SELECT `panel_type` FROM `esi_node_config` WHERE `id` = ?", 'i', $temp['node_id'] ?? 0);
    if ($nc && $nc['panel_type'] === 'marzban') {
        esi_set_step($db, $fromId, 'addPlanMarzban');
        tg_delete();
        tg_send("📋 *تنظیمات Marzban*\n\nJSON اینباندها و پراکسی‌ها را وارد کنید:\nمثال:\n`{\"inbounds\":{\"vless\":[\"VLESS_INBOUND\"]},\"proxies\":{\"vless\":{\"flow\":\"\"}}}`\n\nبرای رد شدن عدد 0 را وارد کنید:", $cancelKeyboard);
    } else {
        esi_set_step($db, $fromId, 'addPlanDesc');
        tg_delete();
        tg_send('📝 توضیحات پلن را وارد کنید (یا 0 برای بدون توضیحات):', $cancelKeyboard);
    }
}

// ── Add Plan: Marzban custom_sni → Description ──────────────────
if ($step === 'addPlanMarzban' && $text !== $btn['cancel']) {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    if ($text !== '0') {
        $parsed = json_decode($text, true);
        if (!$parsed) {
            tg_send('❌ JSON نامعتبر. دوباره وارد کنید:');
        } else {
            $temp['custom_sni'] = $text;
            esi_set_temp($db, $fromId, json_encode($temp));
            esi_set_step($db, $fromId, 'addPlanDesc');
            tg_send('📝 توضیحات پلن را وارد کنید (یا 0 برای بدون توضیحات):');
        }
    } else {
        $temp['custom_sni'] = '';
        esi_set_temp($db, $fromId, json_encode($temp));
        esi_set_step($db, $fromId, 'addPlanDesc');
        tg_send('📝 توضیحات پلن را وارد کنید (یا 0 برای بدون توضیحات):');
    }
}

// ── Add Plan: Description → Save ────────────────────────────────
if ($step === 'addPlanDesc' && $text !== $btn['cancel']) {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $desc = ($text === '0') ? '' : $text;

    esi_execute($db,
        "INSERT INTO `esi_packages`
         (`group_id`, `node_id`, `title`, `protocol`, `volume`, `duration`, `price`, `net_type`, `security`, `description`, `custom_sni`, `active`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
        'iissdiisss' . 's',
        (int) $temp['group_id'], (int) $temp['node_id'],
        $temp['title'], $temp['protocol'],
        (float) $temp['volume'], (int) $temp['duration'], (int) $temp['price'],
        $temp['net_type'], $temp['security'], $desc,
        $temp['custom_sni'] ?? ''
    );

    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');
    tg_send('✅ پلن جدید با موفقیت اضافه شد.', $removeKeyboard);

    // Show plan list for that group
    $gid = (int) $temp['group_id'];
    $plans = esi_fetch_all($db, "SELECT * FROM `esi_packages` WHERE `group_id` = ? ORDER BY `sort_order` ASC, `id` ASC", 'i', $gid);
    $keys = [];
    foreach ($plans as $p) {
        $status = $p['active'] ? '🟢' : '🔴';
        $keys[] = [['text' => "{$status} {$p['title']} - " . format_price($p['price']) . ' T', 'callback_data' => 'viewPlan' . $p['id']]];
    }
    $keys[] = [['text' => '➕ افزودن پلن', 'callback_data' => 'addPlan' . $gid]];
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'viewCategory' . $gid]];
    tg_send('📦 پلن‌ها:', json_encode(['inline_keyboard' => $keys]));
}

// ── View Plan Details ───────────────────────────────────────────
if (preg_match('/^viewPlan(\d+)$/', $data, $m)) {
    $pid = (int) $m[1];
    $p = esi_fetch_one($db, "SELECT p.*, g.title as group_title FROM `esi_packages` p LEFT JOIN `esi_groups` g ON p.`group_id` = g.`id` WHERE p.`id` = ?", 'i', $pid);
    if (!$p) {
        tg_alert('❌ پلن یافت نشد.');
    } else {
        $statusIcon = $p['active'] ? '🟢 فعال' : '🔴 غیرفعال';
        $vol = $p['volume'] > 0 ? $p['volume'] . ' GB' : '♾ نامحدود';
        $dur = $p['duration'] > 0 ? $p['duration'] . ' روز' : '♾ نامحدود';

        $info = "📦 *پلن #{$pid}*\n\n"
            . "📝 عنوان: {$p['title']}\n"
            . "📂 گروه: " . ($p['group_title'] ?? '-') . "\n"
            . "⚙️ پروتکل: {$p['protocol']}\n"
            . "📊 حجم: {$vol}\n"
            . "⏱ مدت: {$dur}\n"
            . "💰 قیمت: " . format_price($p['price']) . " تومان\n"
            . "🌐 شبکه: {$p['net_type']}\n"
            . "🔒 امنیت: {$p['security']}\n"
            . "📊 وضعیت: {$statusIcon}\n"
            . "👥 ظرفیت: " . ($p['capacity'] ?: '♾') . "\n"
            . "🔗 محدودیت IP: " . ($p['limit_ip'] ?: '♾');

        if (!empty($p['description'])) {
            $info .= "\n📝 توضیحات: {$p['description']}";
        }

        $keys = [
            [
                ['text' => '✏️ عنوان', 'callback_data' => 'editPlanTitle' . $pid],
                ['text' => '✏️ قیمت', 'callback_data' => 'editPlanPrice' . $pid],
            ],
            [
                ['text' => '✏️ حجم', 'callback_data' => 'editPlanVolume' . $pid],
                ['text' => '✏️ مدت', 'callback_data' => 'editPlanDuration' . $pid],
            ],
            [
                ['text' => '👥 ظرفیت', 'callback_data' => 'planCapacity' . $pid],
                ['text' => '🔗 محدودیت IP', 'callback_data' => 'planLimitIp' . $pid],
            ],
            [['text' => '🔀 تنظیمات ریلی', 'callback_data' => 'planRelay' . $pid]],
        ];

        if ($p['security'] === 'reality') {
            $keys[] = [['text' => '🛡 تنظیمات Reality', 'callback_data' => 'planReality' . $pid]];
        }

        $keys[] = [['text' => ($p['active'] ? '🔴 غیرفعال کردن' : '🟢 فعال کردن'), 'callback_data' => 'togglePlan' . $pid]];
        $keys[] = [['text' => '🗑 حذف پلن', 'callback_data' => 'deletePlan' . $pid]];
        $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'planList' . $p['group_id']]];
        tg_edit($msgId, $info, json_encode(['inline_keyboard' => $keys]));
    }
}

// ── Edit Plan Title ─────────────────────────────────────────────
if (preg_match('/^editPlanTitle(\d+)$/', $data, $m)) {
    $pid = (int) $m[1];
    tg_delete();
    tg_send("📝 عنوان جدید پلن #{$pid}:", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editPlanTitle_' . $pid);
}
if (preg_match('/^editPlanTitle_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    $pid = (int) $m[1];
    esi_execute($db, "UPDATE `esi_packages` SET `title` = ? WHERE `id` = ?", 'si', $text, $pid);
    tg_send('✅ عنوان بروزرسانی شد.', $removeKeyboard);
    esi_set_step($db, $fromId, 'idle');
}

// ── Edit Plan Price ─────────────────────────────────────────────
if (preg_match('/^editPlanPrice(\d+)$/', $data, $m)) {
    $pid = (int) $m[1];
    tg_delete();
    tg_send("💰 قیمت جدید پلن #{$pid} (تومان):", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editPlanPrice_' . $pid);
}
if (preg_match('/^editPlanPrice_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    $pid = (int) $m[1];
    if (is_numeric($text)) {
        esi_execute($db, "UPDATE `esi_packages` SET `price` = ? WHERE `id` = ?", 'ii', (int) $text, $pid);
        tg_send('✅ قیمت بروزرسانی شد.', $removeKeyboard);
        esi_set_step($db, $fromId, 'idle');
    } else {
        tg_send($msg['number_only'] ?? '❌ لطفاً عدد وارد کنید.');
    }
}

// ── Edit Plan Volume ────────────────────────────────────────────
if (preg_match('/^editPlanVolume(\d+)$/', $data, $m)) {
    $pid = (int) $m[1];
    tg_delete();
    tg_send("📊 حجم جدید پلن #{$pid} (GB، 0 = نامحدود):", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editPlanVolume_' . $pid);
}
if (preg_match('/^editPlanVolume_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    $pid = (int) $m[1];
    if (is_numeric($text)) {
        esi_execute($db, "UPDATE `esi_packages` SET `volume` = ? WHERE `id` = ?", 'di', (float) $text, $pid);
        tg_send('✅ حجم بروزرسانی شد.', $removeKeyboard);
        esi_set_step($db, $fromId, 'idle');
    } else {
        tg_send($msg['number_only'] ?? '❌ لطفاً عدد وارد کنید.');
    }
}

// ── Edit Plan Duration ──────────────────────────────────────────
if (preg_match('/^editPlanDuration(\d+)$/', $data, $m)) {
    $pid = (int) $m[1];
    tg_delete();
    tg_send("⏱ مدت جدید پلن #{$pid} (روز، 0 = نامحدود):", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editPlanDuration_' . $pid);
}
if (preg_match('/^editPlanDuration_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    $pid = (int) $m[1];
    if (is_numeric($text)) {
        esi_execute($db, "UPDATE `esi_packages` SET `duration` = ? WHERE `id` = ?", 'ii', (int) $text, $pid);
        tg_send('✅ مدت بروزرسانی شد.', $removeKeyboard);
        esi_set_step($db, $fromId, 'idle');
    } else {
        tg_send($msg['number_only'] ?? '❌ لطفاً عدد وارد کنید.');
    }
}

// ── Toggle Plan Active ──────────────────────────────────────────
if (preg_match('/^togglePlan(\d+)$/', $data, $m)) {
    $pid = (int) $m[1];
    $p = esi_fetch_one($db, "SELECT `active`, `group_id` FROM `esi_packages` WHERE `id` = ?", 'i', $pid);
    if ($p) {
        $newState = $p['active'] ? 0 : 1;
        esi_execute($db, "UPDATE `esi_packages` SET `active` = ? WHERE `id` = ?", 'ii', $newState, $pid);
        tg_alert($newState ? '✅ پلن فعال شد.' : '🔴 پلن غیرفعال شد.');
    }
}

// ── Delete Plan (Confirm) ───────────────────────────────────────
if (preg_match('/^deletePlan(\d+)$/', $data, $m)) {
    $pid = (int) $m[1];
    $p = esi_fetch_one($db, "SELECT `group_id` FROM `esi_packages` WHERE `id` = ?", 'i', $pid);
    $keys = json_encode(['inline_keyboard' => [
        [
            ['text' => '✅ بله، حذف شود', 'callback_data' => 'confirmDeletePlan' . $pid],
            ['text' => '❌ خیر', 'callback_data' => 'viewPlan' . $pid],
        ],
    ]]);
    tg_edit($msgId, "⚠️ آیا از حذف پلن #{$pid} مطمئن هستید؟", $keys);
}
if (preg_match('/^confirmDeletePlan(\d+)$/', $data, $m)) {
    $pid = (int) $m[1];
    $p = esi_fetch_one($db, "SELECT `group_id` FROM `esi_packages` WHERE `id` = ?", 'i', $pid);
    $gid = $p ? (int) $p['group_id'] : 0;
    esi_execute($db, "DELETE FROM `esi_packages` WHERE `id` = ?", 'i', $pid);
    tg_alert('✅ پلن حذف شد.');

    if ($gid) {
        $plans = esi_fetch_all($db, "SELECT * FROM `esi_packages` WHERE `group_id` = ? ORDER BY `sort_order` ASC", 'i', $gid);
        $keys = [];
        foreach ($plans as $pl) {
            $status = $pl['active'] ? '🟢' : '🔴';
            $keys[] = [['text' => "{$status} {$pl['title']} - " . format_price($pl['price']) . ' T', 'callback_data' => 'viewPlan' . $pl['id']]];
        }
        $keys[] = [['text' => '➕ افزودن پلن', 'callback_data' => 'addPlan' . $gid]];
        $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'viewCategory' . $gid]];
        tg_edit($msgId, '📦 پلن‌ها:', json_encode(['inline_keyboard' => $keys]));
    }
}

// ── Plan Capacity ───────────────────────────────────────────────
if (preg_match('/^planCapacity(\d+)$/', $data, $m)) {
    $pid = (int) $m[1];
    tg_delete();
    tg_send("👥 ظرفیت پلن #{$pid} را وارد کنید (0 = بدون محدودیت):", $cancelKeyboard);
    esi_set_step($db, $fromId, 'planCapacity_' . $pid);
}
if (preg_match('/^planCapacity_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    $pid = (int) $m[1];
    if (is_numeric($text)) {
        esi_execute($db, "UPDATE `esi_packages` SET `capacity` = ? WHERE `id` = ?", 'ii', (int) $text, $pid);
        tg_send('✅ ظرفیت بروزرسانی شد.', $removeKeyboard);
        esi_set_step($db, $fromId, 'idle');
    } else {
        tg_send($msg['number_only'] ?? '❌ لطفاً عدد وارد کنید.');
    }
}

// ── Plan IP Limit ───────────────────────────────────────────────
if (preg_match('/^planLimitIp(\d+)$/', $data, $m)) {
    $pid = (int) $m[1];
    tg_delete();
    tg_send("🔗 محدودیت IP پلن #{$pid} (0 = بدون محدودیت):", $cancelKeyboard);
    esi_set_step($db, $fromId, 'planLimitIp_' . $pid);
}
if (preg_match('/^planLimitIp_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    $pid = (int) $m[1];
    if (is_numeric($text)) {
        esi_execute($db, "UPDATE `esi_packages` SET `limit_ip` = ? WHERE `id` = ?", 'ii', (int) $text, $pid);
        tg_send('✅ محدودیت IP بروزرسانی شد.', $removeKeyboard);
        esi_set_step($db, $fromId, 'idle');
    } else {
        tg_send($msg['number_only'] ?? '❌ لطفاً عدد وارد کنید.');
    }
}

// ── Plan Relay Settings ─────────────────────────────────────────
if (preg_match('/^planRelay(\d+)$/', $data, $m)) {
    $pid = (int) $m[1];
    $p = esi_fetch_one($db, "SELECT * FROM `esi_packages` WHERE `id` = ?", 'i', $pid);
    if (!$p) {
        tg_alert('❌ پلن یافت نشد.');
    } else {
        $relayStatus = $p['relay_mode'] ? '🟢 فعال' : '🔴 غیرفعال';
        $info = "🔀 *تنظیمات ریلی پلن #{$pid}*\n\n"
            . "📊 وضعیت ریلی: {$relayStatus}\n"
            . "🔒 SNI سفارشی: " . ($p['custom_sni'] ?: '-') . "\n"
            . "🔗 پورت سفارشی: " . ($p['custom_port'] ?: '-') . "\n"
            . "🛤 مسیر سفارشی: " . ($p['custom_path'] ?: '-');

        $keys = [
            [['text' => ($p['relay_mode'] ? '🔴 غیرفعال ریلی' : '🟢 فعال ریلی'), 'callback_data' => 'toggleRelay' . $pid]],
            [['text' => '✏️ SNI سفارشی', 'callback_data' => 'editRelaySni' . $pid]],
            [['text' => '✏️ پورت سفارشی', 'callback_data' => 'editRelayPort' . $pid]],
            [['text' => '✏️ مسیر سفارشی', 'callback_data' => 'editRelayPath' . $pid]],
            [['text' => $btn['go_back'], 'callback_data' => 'viewPlan' . $pid]],
        ];
        tg_edit($msgId, $info, json_encode(['inline_keyboard' => $keys]));
    }
}

if (preg_match('/^toggleRelay(\d+)$/', $data, $m)) {
    $pid = (int) $m[1];
    $p = esi_fetch_one($db, "SELECT `relay_mode` FROM `esi_packages` WHERE `id` = ?", 'i', $pid);
    if ($p) {
        $new = $p['relay_mode'] ? 0 : 1;
        esi_execute($db, "UPDATE `esi_packages` SET `relay_mode` = ? WHERE `id` = ?", 'ii', $new, $pid);
        tg_alert($new ? '✅ ریلی فعال شد.' : '🔴 ریلی غیرفعال شد.');
    }
}

if (preg_match('/^editRelaySni(\d+)$/', $data, $m)) {
    tg_delete();
    tg_send("🔒 SNI سفارشی جدید پلن #{$m[1]}:", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editRelaySni_' . $m[1]);
}
if (preg_match('/^editRelaySni_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    esi_execute($db, "UPDATE `esi_packages` SET `custom_sni` = ? WHERE `id` = ?", 'si', $text, (int) $m[1]);
    tg_send('✅ SNI بروزرسانی شد.', $removeKeyboard);
    esi_set_step($db, $fromId, 'idle');
}

if (preg_match('/^editRelayPort(\d+)$/', $data, $m)) {
    tg_delete();
    tg_send("🔗 پورت سفارشی جدید پلن #{$m[1]}:", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editRelayPort_' . $m[1]);
}
if (preg_match('/^editRelayPort_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    if (is_numeric($text)) {
        esi_execute($db, "UPDATE `esi_packages` SET `custom_port` = ? WHERE `id` = ?", 'ii', (int) $text, (int) $m[1]);
        tg_send('✅ پورت بروزرسانی شد.', $removeKeyboard);
        esi_set_step($db, $fromId, 'idle');
    } else {
        tg_send($msg['number_only'] ?? '❌ لطفاً عدد وارد کنید.');
    }
}

if (preg_match('/^editRelayPath(\d+)$/', $data, $m)) {
    tg_delete();
    tg_send("🛤 مسیر سفارشی جدید پلن #{$m[1]}:", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editRelayPath_' . $m[1]);
}
if (preg_match('/^editRelayPath_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    esi_execute($db, "UPDATE `esi_packages` SET `custom_path` = ? WHERE `id` = ?", 'si', $text, (int) $m[1]);
    tg_send('✅ مسیر بروزرسانی شد.', $removeKeyboard);
    esi_set_step($db, $fromId, 'idle');
}

// ── Plan Reality Settings ───────────────────────────────────────
if (preg_match('/^planReality(\d+)$/', $data, $m)) {
    $pid = (int) $m[1];
    $p = esi_fetch_one($db, "SELECT * FROM `esi_packages` WHERE `id` = ?", 'i', $pid);
    if (!$p) {
        tg_alert('❌ پلن یافت نشد.');
    } else {
        $info = "🛡 *تنظیمات Reality پلن #{$pid}*\n\n"
            . "🎯 Dest: " . ($p['reality_dest'] ?: '-') . "\n"
            . "🔒 SNI: " . ($p['reality_sni'] ?: '-') . "\n"
            . "🖐 Fingerprint: " . ($p['reality_fingerprint'] ?: '-') . "\n"
            . "🕷 SpiderX: " . ($p['reality_spider'] ?: '-');

        $keys = [
            [['text' => '✏️ Dest', 'callback_data' => 'editRealityDest' . $pid]],
            [['text' => '✏️ SNI', 'callback_data' => 'editRealitySni' . $pid]],
            [['text' => '✏️ Fingerprint', 'callback_data' => 'editRealityFp' . $pid]],
            [['text' => '✏️ SpiderX', 'callback_data' => 'editRealitySpider' . $pid]],
            [['text' => $btn['go_back'], 'callback_data' => 'viewPlan' . $pid]],
        ];
        tg_edit($msgId, $info, json_encode(['inline_keyboard' => $keys]));
    }
}

if (preg_match('/^editRealityDest(\d+)$/', $data, $m)) {
    tg_delete();
    tg_send("🎯 Dest جدید (مثلاً www.google.com:443):", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editRealityDest_' . $m[1]);
}
if (preg_match('/^editRealityDest_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    esi_execute($db, "UPDATE `esi_packages` SET `reality_dest` = ? WHERE `id` = ?", 'si', $text, (int) $m[1]);
    tg_send('✅ بروزرسانی شد.', $removeKeyboard);
    esi_set_step($db, $fromId, 'idle');
}

if (preg_match('/^editRealitySni(\d+)$/', $data, $m)) {
    tg_delete();
    tg_send("🔒 SNI جدید Reality:", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editRealitySni_' . $m[1]);
}
if (preg_match('/^editRealitySni_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    esi_execute($db, "UPDATE `esi_packages` SET `reality_sni` = ? WHERE `id` = ?", 'si', $text, (int) $m[1]);
    tg_send('✅ بروزرسانی شد.', $removeKeyboard);
    esi_set_step($db, $fromId, 'idle');
}

if (preg_match('/^editRealityFp(\d+)$/', $data, $m)) {
    tg_delete();
    $keys = json_encode(['inline_keyboard' => [
        [
            ['text' => 'chrome', 'callback_data' => 'setFp_chrome_' . $m[1]],
            ['text' => 'firefox', 'callback_data' => 'setFp_firefox_' . $m[1]],
        ],
        [
            ['text' => 'safari', 'callback_data' => 'setFp_safari_' . $m[1]],
            ['text' => 'random', 'callback_data' => 'setFp_random_' . $m[1]],
        ],
    ]]);
    tg_send("🖐 Fingerprint را انتخاب کنید:", $keys);
}
if (preg_match('/^setFp_(\w+)_(\d+)$/', $data, $m)) {
    esi_execute($db, "UPDATE `esi_packages` SET `reality_fingerprint` = ? WHERE `id` = ?", 'si', $m[1], (int) $m[2]);
    tg_alert('✅ بروزرسانی شد.');
}

if (preg_match('/^editRealitySpider(\d+)$/', $data, $m)) {
    tg_delete();
    tg_send("🕷 SpiderX جدید:", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editRealitySpider_' . $m[1]);
}
if (preg_match('/^editRealitySpider_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    esi_execute($db, "UPDATE `esi_packages` SET `reality_spider` = ? WHERE `id` = ?", 'si', $text, (int) $m[1]);
    tg_send('✅ بروزرسانی شد.', $removeKeyboard);
    esi_set_step($db, $fromId, 'idle');
}

// ── Cancel steps ────────────────────────────────────────────────
if (preg_match('/^(addPlan|editPlan|planCapacity|planLimitIp|editRelay|editReality)/', $step) && $text === $btn['cancel']) {
    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');
    tg_send($msg['operation_cancelled'] ?? '❌ عملیات لغو شد.', $removeKeyboard);
}

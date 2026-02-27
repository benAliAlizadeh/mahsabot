<?php
/**
 * MahsaBot - Server / Node Management Handler
 * Admin-only: CRUD for VPN panel nodes (esi_node_info + esi_node_config)
 *
 * @package MahsaBot\Handlers
 */

if (!$isAdmin) return;

// ── Server List ─────────────────────────────────────────────────
if ($data === 'nodeSettings' || $data === 'serverList') {
    $nodes = esi_fetch_all($db, "SELECT ni.*, nc.panel_type, nc.panel_url FROM `esi_node_info` ni LEFT JOIN `esi_node_config` nc ON ni.`id` = nc.`id` ORDER BY ni.`id` ASC");
    $keys = [];
    foreach ($nodes as $n) {
        $status = $n['active'] ? '🟢' : '🔴';
        $keys[] = [['text' => "{$status} {$n['flag']} {$n['title']} [{$n['panel_type']}]", 'callback_data' => 'viewServer' . $n['id']]];
    }
    $keys[] = [['text' => '➕ افزودن سرور', 'callback_data' => 'addServer']];
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
    tg_edit($msgId, '🖥 مدیریت سرورها:', json_encode(['inline_keyboard' => $keys]));
}

// ── Add Server: Start Flow ──────────────────────────────────────
if ($data === 'addServer') {
    tg_delete();
    tg_send('📝 عنوان سرور جدید را وارد کنید:', $cancelKeyboard);
    esi_set_step($db, $fromId, 'addServerTitle');
    esi_set_temp($db, $fromId, '{}');
}

// ── Add Server: Title ───────────────────────────────────────────
if ($step === 'addServerTitle' && $text !== $btn['cancel']) {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['title'] = $text;
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'addServerFlag');
    tg_send('🏳 پرچم/ایموجی سرور را وارد کنید (مثلاً 🇩🇪):');
}

// ── Add Server: Flag ────────────────────────────────────────────
if ($step === 'addServerFlag' && $text !== $btn['cancel']) {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['flag'] = $text;
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'addServerType');
    $keys = json_encode(['inline_keyboard' => [
        [
            ['text' => 'Sanaei (3x-ui)', 'callback_data' => 'srvType_sanaei'],
            ['text' => 'Alireza', 'callback_data' => 'srvType_alireza'],
        ],
        [
            ['text' => 'Normal (Vaxilu)', 'callback_data' => 'srvType_normal'],
            ['text' => 'Marzban', 'callback_data' => 'srvType_marzban'],
        ],
    ]]);
    tg_send('⚙️ نوع پنل را انتخاب کنید:', $keys);
}

// ── Add Server: Panel Type ──────────────────────────────────────
if (preg_match('/^srvType_(sanaei|alireza|normal|marzban)$/', $data, $m) && $step === 'addServerType') {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['panel_type'] = $m[1];
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'addServerUrl');
    tg_delete();
    tg_send('🔗 آدرس پنل را وارد کنید (مثلاً https://panel.example.com:2053):', $cancelKeyboard);
}

// ── Add Server: Panel URL ───────────────────────────────────────
if ($step === 'addServerUrl' && $text !== $btn['cancel']) {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['panel_url'] = rtrim(trim($text), '/');
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'addServerUser');
    tg_send('👤 نام کاربری پنل:');
}

// ── Add Server: Username ────────────────────────────────────────
if ($step === 'addServerUser' && $text !== $btn['cancel']) {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['username'] = trim($text);
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'addServerPass');
    tg_send('🔑 رمز عبور پنل:');
}

// ── Add Server: Password ────────────────────────────────────────
if ($step === 'addServerPass' && $text !== $btn['cancel']) {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['password'] = $text;
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'addServerIp');
    tg_send('🌐 آی‌پی‌های سرور را وارد کنید (با کاما جدا کنید):');
}

// ── Add Server: IPs ─────────────────────────────────────────────
if ($step === 'addServerIp' && $text !== $btn['cancel']) {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['ip'] = trim($text);
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'addServerSni');
    tg_send('🔒 SNI سرور را وارد کنید (خالی = بدون SNI):');
}

// ── Add Server: SNI → Save ──────────────────────────────────────
if ($step === 'addServerSni' && $text !== $btn['cancel']) {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['sni'] = trim($text);

    // Insert into esi_node_info
    esi_execute($db,
        "INSERT INTO `esi_node_info` (`title`, `flag`, `active`, `state`) VALUES (?, ?, 1, 1)",
        'ss', $temp['title'], $temp['flag']
    );
    $nodeId = esi_last_id($db);

    // Insert into esi_node_config (id must match)
    esi_execute($db,
        "INSERT INTO `esi_node_config` (`id`, `panel_url`, `username`, `password`, `panel_type`, `ip`, `sni`) VALUES (?, ?, ?, ?, ?, ?, ?)",
        'issssss', $nodeId, $temp['panel_url'], $temp['username'], $temp['password'], $temp['panel_type'], $temp['ip'], $temp['sni']
    );

    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');
    tg_send('✅ سرور جدید با موفقیت اضافه شد.', $removeKeyboard);

    // Show server list
    $nodes = esi_fetch_all($db, "SELECT ni.*, nc.panel_type FROM `esi_node_info` ni LEFT JOIN `esi_node_config` nc ON ni.`id` = nc.`id` ORDER BY ni.`id` ASC");
    $keys = [];
    foreach ($nodes as $n) {
        $status = $n['active'] ? '🟢' : '🔴';
        $keys[] = [['text' => "{$status} {$n['flag']} {$n['title']}", 'callback_data' => 'viewServer' . $n['id']]];
    }
    $keys[] = [['text' => '➕ افزودن سرور', 'callback_data' => 'addServer']];
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
    tg_send('🖥 مدیریت سرورها:', json_encode(['inline_keyboard' => $keys]));
}

// ── View Server Details ─────────────────────────────────────────
if (preg_match('/^viewServer(\d+)$/', $data, $m)) {
    $sid = (int) $m[1];
    $ni = esi_fetch_one($db, "SELECT * FROM `esi_node_info` WHERE `id` = ?", 'i', $sid);
    $nc = esi_fetch_one($db, "SELECT * FROM `esi_node_config` WHERE `id` = ?", 'i', $sid);
    if (!$ni || !$nc) {
        tg_alert('❌ سرور یافت نشد.');
    } else {
        $statusIcon = $ni['active'] ? '🟢 فعال' : '🔴 غیرفعال';
        $info = "🖥 *سرور #{$sid}*\n\n"
            . "📝 عنوان: {$ni['title']}\n"
            . "🏳 پرچم: {$ni['flag']}\n"
            . "⚙️ نوع پنل: {$nc['panel_type']}\n"
            . "🔗 آدرس: `{$nc['panel_url']}`\n"
            . "🌐 آی‌پی: {$nc['ip']}\n"
            . "🔒 SNI: " . ($nc['sni'] ?: '-') . "\n"
            . "📊 وضعیت: {$statusIcon}";

        $keys = [
            [
                ['text' => '✏️ عنوان', 'callback_data' => 'editServerTitle' . $sid],
                ['text' => '✏️ آدرس', 'callback_data' => 'editServerUrl' . $sid],
            ],
            [
                ['text' => '✏️ آی‌پی', 'callback_data' => 'editServerIp' . $sid],
                ['text' => '✏️ رمز', 'callback_data' => 'editServerPass' . $sid],
            ],
            [['text' => ($ni['active'] ? '🔴 غیرفعال کردن' : '🟢 فعال کردن'), 'callback_data' => 'toggleServer' . $sid]],
            [['text' => '📊 وضعیت آنلاین', 'callback_data' => 'serverStatus' . $sid]],
            [['text' => '🗑 حذف سرور', 'callback_data' => 'deleteServer' . $sid]],
            [['text' => $btn['go_back'], 'callback_data' => 'serverList']],
        ];
        tg_edit($msgId, $info, json_encode(['inline_keyboard' => $keys]));
    }
}

// ── Edit Server Title ───────────────────────────────────────────
if (preg_match('/^editServerTitle(\d+)$/', $data, $m)) {
    $sid = (int) $m[1];
    tg_delete();
    tg_send("📝 عنوان جدید سرور #{$sid} را وارد کنید:", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editServerTitle_' . $sid);
}
if (preg_match('/^editServerTitle_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    $sid = (int) $m[1];
    esi_execute($db, "UPDATE `esi_node_info` SET `title` = ? WHERE `id` = ?", 'si', $text, $sid);
    tg_send('✅ عنوان بروزرسانی شد.', $removeKeyboard);
    esi_set_step($db, $fromId, 'idle');
}

// ── Edit Server URL ─────────────────────────────────────────────
if (preg_match('/^editServerUrl(\d+)$/', $data, $m)) {
    $sid = (int) $m[1];
    tg_delete();
    tg_send("🔗 آدرس جدید پنل سرور #{$sid}:", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editServerUrl_' . $sid);
}
if (preg_match('/^editServerUrl_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    $sid = (int) $m[1];
    $url = rtrim(trim($text), '/');
    esi_execute($db, "UPDATE `esi_node_config` SET `panel_url` = ? WHERE `id` = ?", 'si', $url, $sid);
    tg_send('✅ آدرس بروزرسانی شد.', $removeKeyboard);
    esi_set_step($db, $fromId, 'idle');
}

// ── Edit Server IPs ─────────────────────────────────────────────
if (preg_match('/^editServerIp(\d+)$/', $data, $m)) {
    $sid = (int) $m[1];
    tg_delete();
    tg_send("🌐 آی‌پی‌های جدید سرور #{$sid} (با کاما جدا کنید):", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editServerIp_' . $sid);
}
if (preg_match('/^editServerIp_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    $sid = (int) $m[1];
    esi_execute($db, "UPDATE `esi_node_config` SET `ip` = ? WHERE `id` = ?", 'si', trim($text), $sid);
    tg_send('✅ آی‌پی بروزرسانی شد.', $removeKeyboard);
    esi_set_step($db, $fromId, 'idle');
}

// ── Edit Server Password ────────────────────────────────────────
if (preg_match('/^editServerPass(\d+)$/', $data, $m)) {
    $sid = (int) $m[1];
    tg_delete();
    tg_send("🔑 رمز عبور جدید پنل سرور #{$sid}:", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editServerPass_' . $sid);
}
if (preg_match('/^editServerPass_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    $sid = (int) $m[1];
    esi_execute($db, "UPDATE `esi_node_config` SET `password` = ? WHERE `id` = ?", 'si', $text, $sid);
    tg_send('✅ رمز بروزرسانی شد.', $removeKeyboard);
    esi_set_step($db, $fromId, 'idle');
}

// ── Toggle Server Active ────────────────────────────────────────
if (preg_match('/^toggleServer(\d+)$/', $data, $m)) {
    $sid = (int) $m[1];
    $ni = esi_fetch_one($db, "SELECT `active` FROM `esi_node_info` WHERE `id` = ?", 'i', $sid);
    if ($ni) {
        $newState = $ni['active'] ? 0 : 1;
        esi_execute($db, "UPDATE `esi_node_info` SET `active` = ? WHERE `id` = ?", 'ii', $newState, $sid);
        tg_alert($newState ? '✅ سرور فعال شد.' : '🔴 سرور غیرفعال شد.');
        // Refresh view
        $data = 'viewServer' . $sid;
        $ni = esi_fetch_one($db, "SELECT * FROM `esi_node_info` WHERE `id` = ?", 'i', $sid);
        $nc = esi_fetch_one($db, "SELECT * FROM `esi_node_config` WHERE `id` = ?", 'i', $sid);
        $statusIcon = $ni['active'] ? '🟢 فعال' : '🔴 غیرفعال';
        $info = "🖥 *سرور #{$sid}*\n\n"
            . "📝 عنوان: {$ni['title']}\n"
            . "🏳 پرچم: {$ni['flag']}\n"
            . "⚙️ نوع پنل: {$nc['panel_type']}\n"
            . "🔗 آدرس: `{$nc['panel_url']}`\n"
            . "🌐 آی‌پی: {$nc['ip']}\n"
            . "🔒 SNI: " . ($nc['sni'] ?: '-') . "\n"
            . "📊 وضعیت: {$statusIcon}";
        $keys = [
            [
                ['text' => '✏️ عنوان', 'callback_data' => 'editServerTitle' . $sid],
                ['text' => '✏️ آدرس', 'callback_data' => 'editServerUrl' . $sid],
            ],
            [
                ['text' => '✏️ آی‌پی', 'callback_data' => 'editServerIp' . $sid],
                ['text' => '✏️ رمز', 'callback_data' => 'editServerPass' . $sid],
            ],
            [['text' => ($ni['active'] ? '🔴 غیرفعال کردن' : '🟢 فعال کردن'), 'callback_data' => 'toggleServer' . $sid]],
            [['text' => '📊 وضعیت آنلاین', 'callback_data' => 'serverStatus' . $sid]],
            [['text' => '🗑 حذف سرور', 'callback_data' => 'deleteServer' . $sid]],
            [['text' => $btn['go_back'], 'callback_data' => 'serverList']],
        ];
        tg_edit($msgId, $info, json_encode(['inline_keyboard' => $keys]));
    }
}

// ── Delete Server (Confirm) ─────────────────────────────────────
if (preg_match('/^deleteServer(\d+)$/', $data, $m)) {
    $sid = (int) $m[1];
    $keys = json_encode(['inline_keyboard' => [
        [
            ['text' => '✅ بله، حذف شود', 'callback_data' => 'confirmDeleteServer' . $sid],
            ['text' => '❌ خیر', 'callback_data' => 'viewServer' . $sid],
        ],
    ]]);
    tg_edit($msgId, "⚠️ آیا از حذف سرور #{$sid} مطمئن هستید؟\nتمام گروه‌ها و پلن‌های مرتبط ممکن است تحت تأثیر قرار گیرند.", $keys);
}
if (preg_match('/^confirmDeleteServer(\d+)$/', $data, $m)) {
    $sid = (int) $m[1];
    esi_execute($db, "DELETE FROM `esi_node_info` WHERE `id` = ?", 'i', $sid);
    esi_execute($db, "DELETE FROM `esi_node_config` WHERE `id` = ?", 'i', $sid);
    tg_alert('✅ سرور حذف شد.');
    // Return to server list
    $nodes = esi_fetch_all($db, "SELECT ni.*, nc.panel_type FROM `esi_node_info` ni LEFT JOIN `esi_node_config` nc ON ni.`id` = nc.`id` ORDER BY ni.`id` ASC");
    $keys = [];
    foreach ($nodes as $n) {
        $status = $n['active'] ? '🟢' : '🔴';
        $keys[] = [['text' => "{$status} {$n['flag']} {$n['title']}", 'callback_data' => 'viewServer' . $n['id']]];
    }
    $keys[] = [['text' => '➕ افزودن سرور', 'callback_data' => 'addServer']];
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
    tg_edit($msgId, '🖥 مدیریت سرورها:', json_encode(['inline_keyboard' => $keys]));
}

// ── Server Status (Online Clients) ──────────────────────────────
if (preg_match('/^serverStatus(\d+)$/', $data, $m)) {
    $sid = (int) $m[1];
    $nc = esi_fetch_one($db, "SELECT * FROM `esi_node_config` WHERE `id` = ?", 'i', $sid);
    $ni = esi_fetch_one($db, "SELECT * FROM `esi_node_info` WHERE `id` = ?", 'i', $sid);
    if (!$nc || !$ni) {
        tg_alert('❌ سرور یافت نشد.');
    } else {
        tg_alert('⏳ در حال بررسی...');
        $panelType = $nc['panel_type'] ?? 'sanaei';
        $online = 0;
        $total = 0;

        if ($panelType === 'marzban') {
            $token = marzban_get_token($nc);
            if ($token !== '') {
                $users = marzban_get_users($nc, $token);
                $total = count($users);
                foreach ($users as $u) {
                    if (($u['status'] ?? '') === 'active') $online++;
                }
            }
        } else {
            $inbounds = xui_get_inbounds($db, $nc);
            if ($inbounds['success']) {
                foreach ($inbounds['inbounds'] as $ib) {
                    $clients = json_decode($ib['settings'] ?? '{}', true)['clients'] ?? [];
                    $total += count($clients);
                    if ($ib['enable'] ?? false) {
                        $online += count($clients);
                    }
                }
            }
        }

        $info = "📊 *وضعیت سرور: {$ni['title']}*\n\n"
            . "👥 کل کاربران: {$total}\n"
            . "🟢 آنلاین/فعال: {$online}";

        $keys = json_encode(['inline_keyboard' => [
            [['text' => '🔄 بروزرسانی', 'callback_data' => 'serverStatus' . $sid]],
            [['text' => $btn['go_back'], 'callback_data' => 'viewServer' . $sid]],
        ]]);
        tg_edit($msgId, $info, $keys);
    }
}

// ── Cancel steps ────────────────────────────────────────────────
if (preg_match('/^(addServer|editServer)/', $step) && $text === $btn['cancel']) {
    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');
    tg_send($msg['operation_cancelled'] ?? '❌ عملیات لغو شد.', $removeKeyboard);
}

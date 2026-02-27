<?php
/**
 * MahsaBot - Admin Handler
 * Bot settings, reports, admin management, broadcast, user management
 * 
 * @package MahsaBot
 */

if (!$isAdmin) return;

// ── Admin Panel ─────────────────────────────────────────────────
if ($data === 'adminPanel') {
    tg_edit($msgId, $msg['admin_panel_title'], build_admin_keys());
}

// ── Bot Statistics ──────────────────────────────────────────────
if ($data === 'botStats') {
    $stats = build_stats_keyboard($db);
    tg_edit($msgId, '📊 آمار ربات در لحظه', $stats);
}

// ── Admin List ──────────────────────────────────────────────────
if ($data === 'adminList' && $fromId === ESI_ADMIN_ID) {
    tg_edit($msgId, '👥 لیست ادمین‌ها', build_admin_list_keys($db));
}

// ── Remove Admin ────────────────────────────────────────────────
if (preg_match('/^removeAdmin(\d+)/', $data, $m) && $fromId === ESI_ADMIN_ID) {
    esi_execute($db, "UPDATE `esi_members` SET `is_admin` = 0 WHERE `tg_id` = ?", 'i', (int)$m[1]);
    tg_edit($msgId, '👥 لیست ادمین‌ها', build_admin_list_keys($db));
}

// ── Add Admin ───────────────────────────────────────────────────
if ($data === 'addAdmin' && $fromId === ESI_ADMIN_ID) {
    tg_delete();
    tg_send($msg['enter_admin_id'], $cancelKeyboard);
    esi_set_step($db, $fromId, 'addAdmin');
}
if ($step === 'addAdmin' && $fromId === ESI_ADMIN_ID && $text !== $btn['cancel']) {
    if (is_numeric($text)) {
        esi_execute($db, "UPDATE `esi_members` SET `is_admin` = 1 WHERE `tg_id` = ?", 'i', (int)$text);
        tg_send($msg['admin_added'], $removeKeyboard);
        esi_set_step($db, $fromId, 'idle');
        tg_send('👥 لیست ادمین‌ها', build_admin_list_keys($db));
    } else {
        tg_send($msg['number_only']);
    }
}

// ── Bot Settings ────────────────────────────────────────────────
if ($data === 'botConfig' || preg_match('/^toggleOpt(\w+)/', $data, $m)) {
    if (isset($m[1])) {
        $field = $m[1];
        if ($field === 'cartAutoAcceptMode') {
            $cur = $botOptions[$field] ?? '0';
            $new = $cur === '0' ? '1' : ($cur === '1' ? '2' : '0');
        } else {
            $new = ($botOptions[$field] ?? 'on') === 'on' ? 'off' : 'on';
        }
        $botOptions[$field] = $new;
        esi_save_options($db, 'BOT_CONFIG', $botOptions);
    }
    tg_edit($msgId, $msg['bot_settings_title'], build_bot_settings_keys($botOptions, $btn));
}

// ── Update Config Link State ────────────────────────────────────
if ($data === 'toggleUpdateLinkMode') {
    $new = ($botOptions['updateLinkMode'] ?? 'bot') === 'bot' ? 'web' : 'bot';
    $botOptions['updateLinkMode'] = $new;
    esi_save_options($db, 'BOT_CONFIG', $botOptions);
    tg_edit($msgId, $msg['bot_settings_title'], build_bot_settings_keys($botOptions, $btn));
}

// ── Gateway Settings ────────────────────────────────────────────
if ($data === 'gatewaySettings' || preg_match('/^toggleGw(\w+)/', $data, $m)) {
    if (isset($m[1])) {
        $new = ($botOptions[$m[1]] ?? 'on') === 'on' ? 'off' : 'on';
        $botOptions[$m[1]] = $new;
        esi_save_options($db, 'BOT_CONFIG', $botOptions);
    }
    tg_edit($msgId, $msg['bot_settings_title'], build_gateway_settings_keys($botOptions, $btn));
}

// ── Change Payment Keys ─────────────────────────────────────────
if (preg_match('/^editGwKey(\w+)/', $data, $m)) {
    tg_delete();
    $labels = [
        'nextpay'     => 'کد درگاه نکست‌پی',
        'nowpayment'  => 'کد درگاه NowPayments',
        'zarinpal'    => 'کد درگاه زرین‌پال',
        'bankAccount' => 'شماره کارت بانکی',
        'holderName'  => 'نام صاحب حساب',
        'tronwallet'  => 'آدرس والت ترون',
    ];
    $label = $labels[$m[1]] ?? $m[1];
    tg_send("🔑 لطفاً {$label} جدید را وارد کنید:", $cancelKeyboard);
    esi_set_step($db, $fromId, $data);
}
if (preg_match('/^editGwKey(\w+)/', $step, $m) && $text !== $btn['cancel']) {
    $payKeys[$m[1]] = $text;
    esi_save_options($db, 'GATEWAY_KEYS', $payKeys);
    tg_send($msg['saved_ok'], $removeKeyboard);
    tg_send($msg['bot_settings_title'], build_gateway_settings_keys($botOptions, $btn));
    esi_set_step($db, $fromId, 'idle');
}

// ── Config Remark Type ──────────────────────────────────────────
if ($data === 'toggleRemarkType') {
    $types = ['digits', 'manual', 'idanddigits'];
    $cur = $botOptions['remarkType'] ?? 'digits';
    $idx = array_search($cur, $types);
    $new = $types[($idx + 1) % count($types)];
    $botOptions['remarkType'] = $new;
    esi_save_options($db, 'BOT_CONFIG', $botOptions);
    tg_edit($msgId, $msg['bot_settings_title'], build_bot_settings_keys($botOptions, $btn));
}

// ── Edit Reward/Auto-Accept Time ────────────────────────────────
if (preg_match('/^editTimer(ReportInterval|AutoAcceptMins)/', $data, $m)) {
    tg_delete();
    if ($m[1] === 'ReportInterval') {
        tg_send('⏱ تأخیر ارسال گزارش (ساعت) را وارد کنید:', $cancelKeyboard);
    } else {
        tg_send('⏱ زمان تأیید خودکار کارت‌به‌کارت (دقیقه) را وارد کنید:', $cancelKeyboard);
    }
    esi_set_step($db, $fromId, $data);
}
if (preg_match('/^editTimer(ReportInterval|AutoAcceptMins)/', $step, $m) && $text !== $btn['cancel']) {
    if (is_numeric($text)) {
        $botOptions[$m[1]] = $text;
        esi_save_options($db, 'BOT_CONFIG', $botOptions);
        tg_send($msg['saved_ok'], $removeKeyboard);
        tg_send($msg['bot_settings_title'], build_bot_settings_keys($botOptions, $btn));
        esi_set_step($db, $fromId, 'idle');
    } else {
        tg_send($msg['number_only']);
    }
}

// ── User Lookup ─────────────────────────────────────────────────
if ($data === 'userLookup') {
    tg_delete();
    tg_send($msg['enter_user_id'], $cancelKeyboard);
    esi_set_step($db, $fromId, 'userLookup');
}
if ($step === 'userLookup' && $text !== $btn['cancel']) {
    if (is_numeric($text)) {
        tg_send($msg['please_wait'], $removeKeyboard);
        $userKeys = build_user_info_keys($db, (int)$text);
        if ($userKeys) {
            tg_send("اطلاعات کاربر", $userKeys, 'html');
            esi_set_step($db, $fromId, 'idle');
        } else {
            tg_send($msg['user_not_exists']);
        }
    } else {
        tg_send($msg['number_only']);
    }
}

// ── Direct Message to Specific User ─────────────────────────────
if ($data === 'directMessage') {
    tg_delete();
    tg_send('🆔 آیدی عددی کاربر مقصد را وارد کنید:', $cancelKeyboard);
    esi_set_step($db, $fromId, 'directMessage');
}
if ($step === 'directMessage' && $text !== $btn['cancel']) {
    if (is_numeric($text)) {
        esi_set_step($db, $fromId, 'dmUser' . $text);
        tg_send($msg['send_message_prompt'], $cancelKeyboard);
    } else {
        tg_send($msg['number_only']);
    }
}

// ── Block User ──────────────────────────────────────────────────
if ($data === 'blockUser') {
    tg_delete();
    tg_send($msg['enter_user_id'], $cancelKeyboard);
    esi_set_step($db, $fromId, 'blockUser');
}
if ($step === 'blockUser' && $text !== $btn['cancel']) {
    if (is_numeric($text)) {
        esi_set_step($db, (int)$text, 'banned');
        tg_send('✅ کاربر مسدود شد.', $removeKeyboard);
        tg_send($msg['admin_panel_title'], build_admin_keys());
        esi_set_step($db, $fromId, 'idle');
    } else {
        tg_send($msg['number_only']);
    }
}

// ── Unblock User ────────────────────────────────────────────────
if ($data === 'unblockUser') {
    tg_delete();
    tg_send($msg['enter_user_id'], $cancelKeyboard);
    esi_set_step($db, $fromId, 'unblockUser');
}
if ($step === 'unblockUser' && $text !== $btn['cancel']) {
    if (is_numeric($text)) {
        esi_set_step($db, (int)$text, 'idle');
        tg_send('✅ کاربر آزاد شد.', $removeKeyboard);
        tg_send($msg['admin_panel_title'], build_admin_keys());
        esi_set_step($db, $fromId, 'idle');
    } else {
        tg_send($msg['number_only']);
    }
}

// ── Broadcast Message ───────────────────────────────────────────
if ($data === 'broadcastMsg') {
    tg_delete();
    tg_send('📢 پیام همگانی را ارسال کنید (متن، عکس، ویدیو، صوت):', $cancelKeyboard);
    esi_set_step($db, $fromId, 'broadcastMsg');
}
if ($step === 'broadcastMsg' && $text !== $btn['cancel']) {
    $type = $messageData['file_type'] ?? 'text';
    $fileId = $messageData['file_id'] ?? null;
    $msgText = !empty($messageData['caption']) ? $messageData['caption'] : $text;
    
    $keys = json_encode(['inline_keyboard' => [
        [['text' => $btn['yes_confirm'], 'callback_data' => 'confirmBroadcast']],
        [['text' => $btn['no_cancel'], 'callback_data' => 'cancelBroadcast']],
    ]]);
    
    // Store broadcast data temporarily
    $broadcastData = json_encode([
        'type' => $type, 'file_id' => $fileId, 'text' => $msgText
    ]);
    esi_set_temp($db, $fromId, $broadcastData);
    
    tg_send($msg['broadcast_confirm'], $keys);
}

if ($data === 'confirmBroadcast') {
    $broadcastData = json_decode($member['temp_data'] ?? '{}', true);
    if (!empty($broadcastData)) {
        esi_execute($db,
            "INSERT INTO `esi_broadcast` (`offset_pos`, `media_type`, `content`, `file_ref`, `active`) VALUES (0, ?, ?, ?, 1)",
            'sss', $broadcastData['type'], $broadcastData['text'] ?? '', $broadcastData['file_id'] ?? ''
        );
        tg_edit($msgId, $msg['broadcast_started']);
    }
    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');
}
if ($data === 'cancelBroadcast') {
    tg_edit($msgId, $msg['operation_cancelled']);
    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');
}

// ── Forward to All ──────────────────────────────────────────────
if ($data === 'forwardAll') {
    tg_delete();
    tg_send($msg['forward_message'], $cancelKeyboard);
    esi_set_step($db, $fromId, 'forwardAll');
}
if ($step === 'forwardAll' && $text !== $btn['cancel']) {
    $keys = json_encode(['inline_keyboard' => [
        [['text' => $btn['yes_confirm'], 'callback_data' => 'confirmForward']],
        [['text' => $btn['no_cancel'], 'callback_data' => 'cancelForward']],
    ]]);
    esi_set_temp($db, $fromId, json_encode([
        'chat_id' => $messageData['chat_id'], 'message_id' => $msgId
    ]));
    tg_send($msg['forward_confirm'], $keys);
}
if ($data === 'confirmForward') {
    $fwdData = json_decode($member['temp_data'] ?? '{}', true);
    if (!empty($fwdData)) {
        esi_execute($db,
            "INSERT INTO `esi_broadcast` (`offset_pos`, `media_type`, `content`, `source_chat`, `source_msg`, `active`) VALUES (0, 'forward', '', ?, ?, 1)",
            'si', $fwdData['chat_id'], $fwdData['message_id']
        );
        tg_edit($msgId, $msg['broadcast_started']);
    }
    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');
}
if ($data === 'cancelForward') {
    tg_edit($msgId, $msg['operation_cancelled']);
    esi_set_step($db, $fromId, 'idle');
}

// ── Edit Lock/Reward Channels ───────────────────────────────────
if (preg_match('/^editChannel(Lock|Reward)/', $data, $m)) {
    tg_delete();
    $label = $m[1] === 'Lock' ? 'قفل' : 'پاداش';
    tg_send("📢 آیدی کانال {$label} جدید را وارد کنید (مثل @channel):", $cancelKeyboard);
    esi_set_step($db, $fromId, $data);
}
if (preg_match('/^editChannel(Lock|Reward)/', $step, $m) && $text !== $btn['cancel']) {
    $field = $m[1] === 'Lock' ? 'lockChannel' : 'rewardChannel';
    $botOptions[$field] = $text;
    esi_save_options($db, 'BOT_CONFIG', $botOptions);
    tg_send($msg['saved_ok'], $removeKeyboard);
    tg_send($msg['bot_settings_title'], build_gateway_settings_keys($botOptions, $btn));
    esi_set_step($db, $fromId, 'idle');
}

// ── Invite Settings ─────────────────────────────────────────────
if ($data === 'inviteConfig') {
    $rewardAmount = esi_get_options($db, 'REFERRAL_REWARD')['amount'] ?? 0;
    $keys = json_encode(['inline_keyboard' => [
        [['text' => '🖼 بنر دعوت', 'callback_data' => 'editInviteBanner']],
        [
            ['text' => format_price($rewardAmount) . ' تومان', 'callback_data' => 'editInviteReward'],
            ['text' => 'مقدار پاداش', 'callback_data' => 'noop'],
        ],
        [['text' => $btn['go_back'], 'callback_data' => 'botConfig']],
    ]]);
    $res = tg_edit($msgId, '⚙️ تنظیمات بازاریابی', $keys);
    if (!($res->ok ?? false)) tg_send('⚙️ تنظیمات بازاریابی', $keys);
}

if ($data === 'editInviteReward') {
    tg_delete();
    tg_send('💰 مبلغ پاداش هر زیرمجموعه (تومان) را وارد کنید:', $cancelKeyboard);
    esi_set_step($db, $fromId, 'editInviteReward');
}
if ($step === 'editInviteReward' && $text !== $btn['cancel']) {
    if (is_numeric($text)) {
        esi_save_options($db, 'REFERRAL_REWARD', ['amount' => (int)$text]);
        tg_send($msg['saved_ok'], $removeKeyboard);
        esi_set_step($db, $fromId, 'idle');
    } else {
        tg_send($msg['number_only']);
    }
}

// ── Main Menu Custom Buttons CRUD ───────────────────────────────
if ($data === 'buttonManager') {
    tg_edit($msgId, '🕹 مدیریت دکمه‌های منوی اصلی:', build_custom_button_keys($db));
}
if ($data === 'addCustomBtn') {
    tg_delete();
    tg_send('📝 عنوان دکمه جدید را وارد کنید:', $cancelKeyboard);
    esi_set_step($db, $fromId, 'addCustomBtn');
}
if ($step === 'addCustomBtn' && $text !== $btn['cancel']) {
    esi_set_step($db, $fromId, 'addCustomBtnContent_' . $text);
    tg_send('📝 متن/پاسخ دکمه را وارد کنید:', $cancelKeyboard);
}
if (preg_match('/^addCustomBtnContent_(.+)/', $step, $m) && $text !== $btn['cancel']) {
    $btnTitle = $m[1];
    esi_save_options($db, 'MAIN_BTN_' . $btnTitle, ['content' => $text]);
    tg_send($msg['saved_ok'], $removeKeyboard);
    tg_send('🕹 مدیریت دکمه‌ها:', build_custom_button_keys($db));
    esi_set_step($db, $fromId, 'idle');
}
if (preg_match('/^delCustomBtn(\d+)/', $data, $m)) {
    esi_execute($db, "DELETE FROM `esi_options` WHERE `id` = ? AND `option_key` LIKE 'MAIN_BTN_%'", 'i', (int)$m[1]);
    tg_edit($msgId, '🕹 مدیریت دکمه‌ها:', build_custom_button_keys($db));
}
if (preg_match('/^customBtn(\d+)/', $data, $m)) {
    $row = esi_fetch_one($db, "SELECT * FROM `esi_options` WHERE `id` = ?", 'i', (int)$m[1]);
    if ($row) {
        $content = json_decode($row['option_value'] ?? '{}', true)['content'] ?? '';
        $keys = json_encode(['inline_keyboard' => [
            [['text' => $btn['go_back'], 'callback_data' => 'mainMenu']],
        ]]);
        tg_edit($msgId, $content, $keys);
    }
}

// ── Increase/Decrease User Wallet ───────────────────────────────
if ($data === 'addUserBalance') {
    tg_delete();
    tg_send($msg['enter_user_id'], $cancelKeyboard);
    esi_set_step($db, $fromId, 'addUserBalance');
}
if ($step === 'addUserBalance' && $text !== $btn['cancel']) {
    if (is_numeric($text)) {
        esi_set_step($db, $fromId, 'addBalanceAmount_' . $text);
        tg_send($msg['enter_increase_amount'], $cancelKeyboard);
    } else {
        tg_send($msg['number_only']);
    }
}
if (preg_match('/^addBalanceAmount_(\d+)/', $step, $m) && $text !== $btn['cancel']) {
    if (is_numeric($text)) {
        $targetId = (int)$m[1];
        $amount = (int)$text;
        esi_execute($db, "UPDATE `esi_members` SET `balance` = `balance` + ? WHERE `tg_id` = ?", 'ii', $amount, $targetId);
        tg_send(fill_template($msg['wallet_charged'], ['AMOUNT' => format_price($amount)]), null, null, $targetId);
        tg_send("✅ مبلغ " . format_price($amount) . " تومان به کیف پول کاربر اضافه شد.", $removeKeyboard);
        tg_send($msg['admin_panel_title'], build_admin_keys());
        esi_set_step($db, $fromId, 'idle');
    } else {
        tg_send($msg['number_only']);
    }
}

if ($data === 'subUserBalance') {
    tg_delete();
    tg_send($msg['enter_user_id'], $cancelKeyboard);
    esi_set_step($db, $fromId, 'subUserBalance');
}
if ($step === 'subUserBalance' && $text !== $btn['cancel']) {
    if (is_numeric($text)) {
        esi_set_step($db, $fromId, 'subBalanceAmount_' . $text);
        tg_send($msg['enter_decrease_amount'], $cancelKeyboard);
    } else {
        tg_send($msg['number_only']);
    }
}
if (preg_match('/^subBalanceAmount_(\d+)/', $step, $m) && $text !== $btn['cancel']) {
    if (is_numeric($text)) {
        $targetId = (int)$m[1];
        $amount = (int)$text;
        esi_execute($db, "UPDATE `esi_members` SET `balance` = `balance` - ? WHERE `tg_id` = ?", 'ii', $amount, $targetId);
        tg_send(fill_template($msg['wallet_decreased'], ['AMOUNT' => format_price($amount)]), null, null, $targetId);
        tg_send("✅ مبلغ " . format_price($amount) . " تومان از کیف پول کاربر کسر شد.", $removeKeyboard);
        tg_send($msg['admin_panel_title'], build_admin_keys());
        esi_set_step($db, $fromId, 'idle');
    } else {
        tg_send($msg['number_only']);
    }
}

// ── Helper: Build Stats Keyboard ────────────────────────────────
function build_stats_keyboard(mysqli $db): string {
    global $btn;
    $totalUsers = esi_fetch_one($db, "SELECT COUNT(*) as cnt FROM `esi_members`")['cnt'] ?? 0;
    $todayStart = strtotime('today');
    $monthStart = strtotime('first day of this month');
    
    $todaySales = esi_fetch_one($db, 
        "SELECT COALESCE(SUM(`amount`),0) as total FROM `esi_subscriptions` WHERE `created_at` >= ? AND `status` = 1", 
        'i', $todayStart
    )['total'] ?? 0;
    
    $monthSales = esi_fetch_one($db, 
        "SELECT COALESCE(SUM(`amount`),0) as total FROM `esi_subscriptions` WHERE `created_at` >= ? AND `status` = 1", 
        'i', $monthStart
    )['total'] ?? 0;
    
    $totalWallet = esi_fetch_one($db, "SELECT COALESCE(SUM(`balance`),0) as total FROM `esi_members`")['total'] ?? 0;
    
    $keys = [
        [['text' => "👥 کل کاربران: {$totalUsers}", 'callback_data' => 'noop']],
        [['text' => "💰 فروش امروز: " . format_price($todaySales) . " تومان", 'callback_data' => 'noop']],
        [['text' => "📊 فروش ماه: " . format_price($monthSales) . " تومان", 'callback_data' => 'noop']],
        [['text' => "💳 کل موجودی‌ها: " . format_price($totalWallet) . " تومان", 'callback_data' => 'noop']],
        [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']],
    ];
    return json_encode(['inline_keyboard' => $keys]);
}

// ── Helper: Build Admin List Keys ───────────────────────────────
function build_admin_list_keys(mysqli $db): string {
    global $btn;
    $admins = esi_fetch_all($db, "SELECT * FROM `esi_members` WHERE `is_admin` = 1");
    $keys = [];
    foreach ($admins as $adm) {
        $keys[] = [
            ['text' => '❌', 'callback_data' => 'removeAdmin' . $adm['tg_id']],
            ['text' => $adm['display_name'], 'callback_data' => 'noop'],
            ['text' => $adm['tg_id'], 'callback_data' => 'noop'],
        ];
    }
    $keys[] = [['text' => '➕ افزودن ادمین', 'callback_data' => 'addAdmin']];
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
    return json_encode(['inline_keyboard' => $keys]);
}

// ── Helper: Bot Settings Keys ───────────────────────────────────
function build_bot_settings_keys(array $opts, array $btn): string {
    $toggle = fn($key) => ($opts[$key] ?? 'off') === 'on' ? $btn['status_on'] : $btn['status_off'];
    
    $keys = [
        [['text' => $toggle('requirePhone') . ' دریافت شماره', 'callback_data' => 'toggleOptrequirePhone']],
        [['text' => $toggle('requireIranPhone') . ' فقط شماره ایرانی', 'callback_data' => 'toggleOptrequireIranPhone']],
        [['text' => $toggle('sellActive') . ' فروش', 'callback_data' => 'toggleOptsellActive']],
        [['text' => $toggle('searchActive') . ' جستجوی سرویس', 'callback_data' => 'toggleOptsearchActive']],
        [['text' => $toggle('walletActive') . ' کیف پول', 'callback_data' => 'toggleOptwalletActive']],
        [['text' => $toggle('subLinkActive') . ' لینک اشتراک', 'callback_data' => 'toggleOptsubLinkActive']],
        [['text' => $toggle('switchLocationActive') . ' تغییر لوکیشن', 'callback_data' => 'toggleOptswitchLocationActive']],
        [['text' => $toggle('addTimeActive') . ' افزایش زمان', 'callback_data' => 'toggleOptaddTimeActive']],
        [['text' => $toggle('addVolumeActive') . ' افزایش حجم', 'callback_data' => 'toggleOptaddVolumeActive']],
        [['text' => $toggle('customPlanActive') . ' پلن دلخواه', 'callback_data' => 'toggleOptcustomPlanActive']],
        [['text' => $toggle('weswapActive') . ' ارزی ریالی', 'callback_data' => 'toggleOptweswapActive']],
        [['text' => $toggle('testAccount') . ' اکانت تست', 'callback_data' => 'toggleOpttestAccount']],
        [['text' => $toggle('agencyActive') . ' سیستم نمایندگی', 'callback_data' => 'toggleOptagencyActive']],
        [['text' => '⏱ تنظیم تأخیر گزارش', 'callback_data' => 'editTimerReportInterval']],
        [['text' => '⏱ زمان تأیید خودکار', 'callback_data' => 'editTimerAutoAcceptMins']],
        [['text' => '📢 تنظیمات بازاریابی', 'callback_data' => 'inviteConfig']],
        [
            ['text' => '🔗 حالت آپدیت لینک: ' . ($opts['updateLinkMode'] ?? 'bot'), 'callback_data' => 'toggleUpdateLinkMode'],
        ],
        [
            ['text' => '✏️ نوع ریمارک: ' . ($opts['remarkType'] ?? 'digits'), 'callback_data' => 'toggleRemarkType'],
        ],
        [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']],
    ];
    return json_encode(['inline_keyboard' => $keys]);
}

// ── Helper: Gateway Settings Keys ───────────────────────────────
function build_gateway_settings_keys(array $opts, array $btn): string {
    $toggle = fn($key) => ($opts[$key] ?? 'off') === 'on' ? $btn['status_on'] : $btn['status_off'];
    
    $keys = [
        [['text' => $toggle('cartToCartActive') . ' کارت به کارت', 'callback_data' => 'toggleGwcartToCartActive']],
        [['text' => $toggle('nextpayActive') . ' نکست‌پی', 'callback_data' => 'toggleGwnextpayActive']],
        [['text' => $toggle('zarinpalActive') . ' زرین‌پال', 'callback_data' => 'toggleGwzarinpalActive']],
        [['text' => $toggle('nowpayWallet') . ' NowPayments والت', 'callback_data' => 'toggleGwnowpayWallet']],
        [['text' => $toggle('nowpayOther') . ' NowPayments سایر', 'callback_data' => 'toggleGwnowpayOther']],
        [['text' => '🔑 کد نکست‌پی', 'callback_data' => 'editGwKeynextpay']],
        [['text' => '🔑 کد زرین‌پال', 'callback_data' => 'editGwKeyzarinpal']],
        [['text' => '🔑 کد NowPayments', 'callback_data' => 'editGwKeynowpayment']],
        [['text' => '💳 شماره کارت', 'callback_data' => 'editGwKeybankAccount']],
        [['text' => '👤 نام صاحب حساب', 'callback_data' => 'editGwKeyholderName']],
        [['text' => '₮ آدرس والت ترون', 'callback_data' => 'editGwKeytronwallet']],
        [['text' => '📢 کانال قفل', 'callback_data' => 'editChannelLock']],
        [['text' => '📢 کانال گزارش', 'callback_data' => 'editChannelReward']],
        [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']],
    ];
    return json_encode(['inline_keyboard' => $keys]);
}

// ── Helper: Custom Button Keys ──────────────────────────────────
function build_custom_button_keys(mysqli $db): string {
    global $btn;
    $rows = esi_fetch_all($db, "SELECT * FROM `esi_options` WHERE `option_key` LIKE 'MAIN_BTN_%'");
    $keys = [];
    foreach ($rows as $row) {
        $title = str_replace('MAIN_BTN_', '', $row['option_key']);
        $keys[] = [
            ['text' => '❌', 'callback_data' => 'delCustomBtn' . $row['id']],
            ['text' => $title, 'callback_data' => 'noop'],
        ];
    }
    $keys[] = [['text' => '➕ افزودن دکمه', 'callback_data' => 'addCustomBtn']];
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
    return json_encode(['inline_keyboard' => $keys]);
}

// ── Helper: Build User Info Keys ────────────────────────────────
function build_user_info_keys(mysqli $db, int $userId): ?string {
    global $btn;
    $target = esi_get_member($db, $userId);
    if (!$target) return null;
    
    $orderCount = esi_count_subscriptions($db, $userId);
    $joinDate = jdate('Y-m-d', $target['joined_at'] ?? 0);
    
    $keys = [
        [['text' => "👤 {$target['display_name']}", 'callback_data' => 'noop']],
        [['text' => "🆔 {$target['tg_id']}", 'callback_data' => 'noop']],
        [['text' => "💰 موجودی: " . format_price($target['balance']), 'callback_data' => 'noop']],
        [['text' => "📦 سفارشات: {$orderCount}", 'callback_data' => 'noop']],
        [['text' => "📅 عضویت: {$joinDate}", 'callback_data' => 'noop']],
        [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']],
    ];
    return json_encode(['inline_keyboard' => $keys]);
}

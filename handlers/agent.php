<?php
/**
 * MahsaBot - Agency / Dealer System Handler
 * Agent panel for users, admin agent management.
 *
 * @package MahsaBot\Handlers
 */

// ═════════════════════════════════════════════════════════════════
// USER-FACING: Agent Panel
// ═════════════════════════════════════════════════════════════════

// ── Agent Panel ─────────────────────────────────────────────────
if ($data === 'agencyPanel' && ($member['is_agent'] ?? 0) == 1) {
    $keys = json_encode(['inline_keyboard' => [
        [
            ['text' => '🛒 خرید تکی', 'callback_data' => 'agentSingleBuy'],
            ['text' => '📦 خرید عمده', 'callback_data' => 'agentBulkBuy'],
        ],
        [['text' => '📋 کانفیگ‌های نمایندگی', 'callback_data' => 'agentServiceList']],
        [['text' => '📊 گزارش فروش', 'callback_data' => 'agentReport']],
        [['text' => $btn['go_back'], 'callback_data' => 'mainMenu']],
    ]]);
    tg_edit($msgId, '🏪 *پنل نمایندگی*', $keys);
}

// ── Agent Report ────────────────────────────────────────────────
if ($data === 'agentReport' && ($member['is_agent'] ?? 0) == 1) {
    $totalSold = esi_fetch_one($db,
        "SELECT COUNT(*) as cnt FROM `esi_subscriptions` WHERE `member_id` = ? AND `agent_purchase` = 1 AND `status` = 1",
        'i', $fromId
    )['cnt'] ?? 0;

    $totalEarned = esi_fetch_one($db,
        "SELECT COALESCE(SUM(`amount`), 0) as total FROM `esi_subscriptions` WHERE `member_id` = ? AND `agent_purchase` = 1 AND `status` = 1",
        'i', $fromId
    )['total'] ?? 0;

    // Calculate commission based on discount config
    $discountConfig = json_decode($member['discount_config'] ?? '{}', true);
    $normalDiscount = (int) ($discountConfig['normal'] ?? 0);

    $info = "📊 *گزارش نمایندگی*\n\n"
        . "📦 تعداد فروش: {$totalSold}\n"
        . "💰 مجموع فروش: " . format_price((int) $totalEarned) . " تومان\n"
        . "📊 درصد تخفیف پایه: {$normalDiscount}%";

    $keys = json_encode(['inline_keyboard' => [
        [['text' => $btn['go_back'], 'callback_data' => 'agencyPanel']],
    ]]);
    tg_edit($msgId, $info, $keys);
}

// ═════════════════════════════════════════════════════════════════
// ADMIN: Agent Management
// ═════════════════════════════════════════════════════════════════

if (!$isAdmin) return;

// ── Admin Agent List ────────────────────────────────────────────
if ($data === 'agentManager' || $data === 'adminAgentList') {
    $agents = esi_fetch_all($db, "SELECT * FROM `esi_members` WHERE `is_agent` = 1 ORDER BY `id` DESC");
    $keys = [];
    foreach ($agents as $ag) {
        $name = $ag['display_name'] ?: $ag['tg_id'];
        $keys[] = [['text' => "🏪 {$name} ({$ag['tg_id']})", 'callback_data' => 'viewAgent' . $ag['tg_id']]];
    }
    $keys[] = [['text' => '➕ افزودن نماینده', 'callback_data' => 'addAgentStart']];
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
    tg_edit($msgId, '🏪 مدیریت نمایندگان:', json_encode(['inline_keyboard' => $keys]));
}

// ── View Agent ──────────────────────────────────────────────────
if (preg_match('/^viewAgent(\d+)$/', $data, $m)) {
    $agId = (int) $m[1];
    $ag = esi_get_member($db, $agId);
    if (!$ag || $ag['is_agent'] != 1) {
        tg_alert('❌ نماینده یافت نشد.');
    } else {
        $discountConfig = json_decode($ag['discount_config'] ?? '{}', true);
        $normalDisc = $discountConfig['normal'] ?? 0;
        $planDiscs = $discountConfig['plans'] ?? [];
        $serverDiscs = $discountConfig['servers'] ?? [];

        $agentSales = esi_fetch_one($db,
            "SELECT COUNT(*) as cnt, COALESCE(SUM(`amount`), 0) as total FROM `esi_subscriptions`
             WHERE `member_id` = ? AND `agent_purchase` = 1 AND `status` = 1",
            'i', $agId
        );

        $info = "🏪 *نماینده: {$ag['display_name']}*\n\n"
            . "🆔 آیدی: `{$agId}`\n"
            . "💰 موجودی: " . format_price((int) $ag['balance']) . " تومان\n"
            . "📦 فروش: " . ($agentSales['cnt'] ?? 0) . "\n"
            . "💵 مبلغ فروش: " . format_price((int) ($agentSales['total'] ?? 0)) . " تومان\n"
            . "📊 تخفیف پایه: {$normalDisc}%";

        if (!empty($planDiscs)) {
            $info .= "\n📋 تخفیف پلن‌ها: " . json_encode($planDiscs, JSON_UNESCAPED_UNICODE);
        }
        if (!empty($serverDiscs)) {
            $info .= "\n🖥 تخفیف سرورها: " . json_encode($serverDiscs, JSON_UNESCAPED_UNICODE);
        }

        $keys = [
            [['text' => '✏️ ویرایش تخفیف', 'callback_data' => 'editAgentDiscount' . $agId]],
            [['text' => '🗑 حذف نمایندگی', 'callback_data' => 'removeAgent' . $agId]],
            [['text' => $btn['go_back'], 'callback_data' => 'adminAgentList']],
        ];
        tg_edit($msgId, $info, json_encode(['inline_keyboard' => $keys]));
    }
}

// ── Add Agent: Enter User ID ────────────────────────────────────
if ($data === 'addAgentStart') {
    tg_delete();
    tg_send('🆔 آیدی عددی کاربر مورد نظر را وارد کنید:', $cancelKeyboard);
    esi_set_step($db, $fromId, 'addAgentUserId');
}
if ($step === 'addAgentUserId' && $text !== $btn['cancel']) {
    if (!is_numeric($text)) {
        tg_send($msg['number_only'] ?? '❌ لطفاً عدد وارد کنید.');
    } else {
        $targetId = (int) $text;
        $target = esi_get_member($db, $targetId);
        if (!$target) {
            tg_send('❌ کاربر یافت نشد. ابتدا باید ربات را استارت کند.');
        } elseif ($target['is_agent'] == 1) {
            tg_send('❌ این کاربر قبلاً نماینده است.');
            esi_set_step($db, $fromId, 'idle');
        } else {
            esi_execute($db, "UPDATE `esi_members` SET `is_agent` = 1 WHERE `tg_id` = ?", 'i', $targetId);
            tg_send("✅ کاربر {$target['display_name']} ({$targetId}) به عنوان نماینده اضافه شد.", $removeKeyboard);
            esi_set_step($db, $fromId, 'idle');

            // Notify user
            tg_send('🎉 شما به عنوان نماینده فروش ربات انتخاب شدید!', null, 'MarkDown', $targetId);
        }
    }
}

// ── Add Agent by callback ───────────────────────────────────────
if (preg_match('/^addAgent(\d+)$/', $data, $m)) {
    $targetId = (int) $m[1];
    $target = esi_get_member($db, $targetId);
    if (!$target) {
        tg_alert('❌ کاربر یافت نشد.');
    } elseif ($target['is_agent'] == 1) {
        tg_alert('❌ قبلاً نماینده است.');
    } else {
        esi_execute($db, "UPDATE `esi_members` SET `is_agent` = 1 WHERE `tg_id` = ?", 'i', $targetId);
        tg_alert('✅ نمایندگی فعال شد.');
        tg_send('🎉 شما به عنوان نماینده فروش ربات انتخاب شدید!', null, 'MarkDown', $targetId);
    }
}

// ── Remove Agent ────────────────────────────────────────────────
if (preg_match('/^removeAgent(\d+)$/', $data, $m)) {
    $agId = (int) $m[1];
    $keys = json_encode(['inline_keyboard' => [
        [
            ['text' => '✅ بله', 'callback_data' => 'confirmRemoveAgent' . $agId],
            ['text' => '❌ خیر', 'callback_data' => 'viewAgent' . $agId],
        ],
    ]]);
    tg_edit($msgId, "⚠️ آیا از حذف نمایندگی کاربر {$agId} مطمئن هستید؟", $keys);
}
if (preg_match('/^confirmRemoveAgent(\d+)$/', $data, $m)) {
    $agId = (int) $m[1];
    esi_execute($db, "UPDATE `esi_members` SET `is_agent` = 0 WHERE `tg_id` = ?", 'i', $agId);
    tg_alert('✅ نمایندگی حذف شد.');
    tg_send('⚠️ نمایندگی شما لغو شد.', null, 'MarkDown', $agId);

    // Return to agent list
    $agents = esi_fetch_all($db, "SELECT * FROM `esi_members` WHERE `is_agent` = 1 ORDER BY `id` DESC");
    $keys = [];
    foreach ($agents as $ag) {
        $name = $ag['display_name'] ?: $ag['tg_id'];
        $keys[] = [['text' => "🏪 {$name} ({$ag['tg_id']})", 'callback_data' => 'viewAgent' . $ag['tg_id']]];
    }
    $keys[] = [['text' => '➕ افزودن نماینده', 'callback_data' => 'addAgentStart']];
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
    tg_edit($msgId, '🏪 مدیریت نمایندگان:', json_encode(['inline_keyboard' => $keys]));
}

// ── Edit Agent Discount Config ──────────────────────────────────
if (preg_match('/^editAgentDiscount(\d+)$/', $data, $m)) {
    $agId = (int) $m[1];
    $ag = esi_get_member($db, $agId);
    if (!$ag) {
        tg_alert('❌ نماینده یافت نشد.');
    } else {
        $current = $ag['discount_config'] ?? '{}';
        tg_delete();
        tg_send(
            "✏️ *ویرایش تخفیف نماینده {$agId}*\n\n"
            . "تنظیمات فعلی:\n`{$current}`\n\n"
            . "فرمت JSON را وارد کنید:\n"
            . "`{\"normal\":10, \"plans\":{\"5\":15}, \"servers\":{\"2\":20}}`\n\n"
            . "• normal: درصد تخفیف پایه\n"
            . "• plans: تخفیف ویژه به ازای شناسه پلن\n"
            . "• servers: تخفیف ویژه به ازای شناسه سرور",
            $cancelKeyboard
        );
        esi_set_step($db, $fromId, 'agentDiscountEdit_' . $agId);
    }
}

if (preg_match('/^agentDiscountEdit_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    $agId = (int) $m[1];
    $parsed = json_decode($text, true);
    if (!$parsed || !is_array($parsed)) {
        tg_send('❌ JSON نامعتبر. دوباره وارد کنید:');
    } else {
        esi_update_member($db, $agId, 'discount_config', json_encode($parsed, JSON_UNESCAPED_UNICODE));
        tg_send("✅ تخفیف نماینده {$agId} بروزرسانی شد.", $removeKeyboard);
        esi_set_step($db, $fromId, 'idle');
    }
}

// ── Cancel steps ────────────────────────────────────────────────
if (preg_match('/^(addAgent|agentDiscount)/', $step) && $text === $btn['cancel']) {
    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');
    tg_send($msg['operation_cancelled'] ?? '❌ عملیات لغو شد.', $removeKeyboard);
}

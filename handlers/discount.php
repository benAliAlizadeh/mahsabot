<?php
/**
 * MahsaBot - Coupon / Discount Management Handler
 * Admin-only: CRUD for discount coupons (esi_coupons)
 *
 * @package MahsaBot\Handlers
 */

if (!$isAdmin) return;

// ── Discount List ───────────────────────────────────────────────
if ($data === 'couponSettings' || $data === 'discountList') {
    $coupons = esi_fetch_all($db, "SELECT * FROM `esi_coupons` ORDER BY `id` DESC");
    $keys = [];
    foreach ($coupons as $c) {
        $status = $c['active'] ? '🟢' : '🔴';
        $typeLabel = $c['type'] === 'percent' ? $c['amount'] . '%' : format_price($c['amount']) . ' T';
        $keys[] = [['text' => "{$status} {$c['code']} ({$typeLabel})", 'callback_data' => 'viewDiscount' . $c['id']]];
    }
    $keys[] = [['text' => '➕ افزودن کد تخفیف', 'callback_data' => 'addDiscount']];
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
    tg_edit($msgId, '🎟 مدیریت کدهای تخفیف:', json_encode(['inline_keyboard' => $keys]));
}

// ── Add Discount: Start → Code ──────────────────────────────────
if ($data === 'addDiscount') {
    tg_delete();
    tg_send('📝 کد تخفیف را وارد کنید (بدون فاصله):', $cancelKeyboard);
    esi_set_step($db, $fromId, 'addDiscCode');
    esi_set_temp($db, $fromId, '{}');
}

// ── Add Discount: Code → Type ───────────────────────────────────
if ($step === 'addDiscCode' && $text !== $btn['cancel']) {
    $code = preg_replace('/\s+/', '', $text);
    // Check uniqueness
    $exists = esi_fetch_one($db, "SELECT `id` FROM `esi_coupons` WHERE `code` = ?", 's', $code);
    if ($exists) {
        tg_send('❌ این کد تخفیف قبلاً ثبت شده. کد دیگری وارد کنید:');
    } else {
        $temp = ['code' => $code];
        esi_set_temp($db, $fromId, json_encode($temp));
        esi_set_step($db, $fromId, 'addDiscType');
        $keys = json_encode(['inline_keyboard' => [
            [
                ['text' => '📊 درصدی', 'callback_data' => 'discType_percent'],
                ['text' => '💰 مبلغ ثابت', 'callback_data' => 'discType_fixed'],
            ],
        ]]);
        tg_send('🎟 نوع تخفیف را انتخاب کنید:', $keys);
    }
}

// ── Add Discount: Type → Amount ─────────────────────────────────
if (preg_match('/^discType_(percent|fixed)$/', $data, $m) && $step === 'addDiscType') {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $temp['type'] = $m[1];
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'addDiscAmount');
    tg_delete();
    $label = $m[1] === 'percent' ? 'درصد تخفیف (1-100)' : 'مبلغ تخفیف (تومان)';
    tg_send("💰 {$label} را وارد کنید:", $cancelKeyboard);
}

// ── Add Discount: Amount → Max Uses ─────────────────────────────
if ($step === 'addDiscAmount' && $text !== $btn['cancel']) {
    if (!is_numeric($text) || (int) $text <= 0) {
        tg_send('❌ لطفاً یک عدد مثبت وارد کنید.');
    } else {
        $temp = json_decode($member['temp_data'] ?? '{}', true);
        $temp['amount'] = (int) $text;
        esi_set_temp($db, $fromId, json_encode($temp));
        esi_set_step($db, $fromId, 'addDiscMax');
        tg_send('👥 حداکثر تعداد استفاده (0 = نامحدود):');
    }
}

// ── Add Discount: Max Uses → Expiry ─────────────────────────────
if ($step === 'addDiscMax' && $text !== $btn['cancel']) {
    if (!is_numeric($text)) {
        tg_send($msg['number_only'] ?? '❌ لطفاً عدد وارد کنید.');
    } else {
        $temp = json_decode($member['temp_data'] ?? '{}', true);
        $temp['max_uses'] = (int) $text;
        esi_set_temp($db, $fromId, json_encode($temp));
        esi_set_step($db, $fromId, 'addDiscExpiry');
        tg_send("📅 تعداد روز تا انقضا (0 = بدون انقضا):");
    }
}

// ── Add Discount: Expiry → Save ─────────────────────────────────
if ($step === 'addDiscExpiry' && $text !== $btn['cancel']) {
    if (!is_numeric($text)) {
        tg_send($msg['number_only'] ?? '❌ لطفاً عدد وارد کنید.');
    } else {
        $temp = json_decode($member['temp_data'] ?? '{}', true);
        $days = (int) $text;
        $expiresAt = $days > 0 ? time() + ($days * 86400) : 0;

        esi_execute($db,
            "INSERT INTO `esi_coupons` (`code`, `type`, `amount`, `max_uses`, `active`, `expires_at`, `created_at`)
             VALUES (?, ?, ?, ?, 1, ?, ?)",
            'ssiiii',
            $temp['code'], $temp['type'], (int) $temp['amount'],
            (int) $temp['max_uses'], $expiresAt, time()
        );

        esi_set_step($db, $fromId, 'idle');
        esi_set_temp($db, $fromId, '');
        tg_send("✅ کد تخفیف `{$temp['code']}` اضافه شد.", $removeKeyboard);

        // Refresh list
        $coupons = esi_fetch_all($db, "SELECT * FROM `esi_coupons` ORDER BY `id` DESC");
        $keys = [];
        foreach ($coupons as $c) {
            $status = $c['active'] ? '🟢' : '🔴';
            $typeLabel = $c['type'] === 'percent' ? $c['amount'] . '%' : format_price($c['amount']) . ' T';
            $keys[] = [['text' => "{$status} {$c['code']} ({$typeLabel})", 'callback_data' => 'viewDiscount' . $c['id']]];
        }
        $keys[] = [['text' => '➕ افزودن کد تخفیف', 'callback_data' => 'addDiscount']];
        $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
        tg_send('🎟 مدیریت کدهای تخفیف:', json_encode(['inline_keyboard' => $keys]));
    }
}

// ── View Discount ───────────────────────────────────────────────
if (preg_match('/^viewDiscount(\d+)$/', $data, $m)) {
    $cid = (int) $m[1];
    $c = esi_fetch_one($db, "SELECT * FROM `esi_coupons` WHERE `id` = ?", 'i', $cid);
    if (!$c) {
        tg_alert('❌ کد تخفیف یافت نشد.');
    } else {
        $statusIcon = $c['active'] ? '🟢 فعال' : '🔴 غیرفعال';
        $typeLabel = $c['type'] === 'percent' ? 'درصدی' : 'مبلغ ثابت';
        $amountLabel = $c['type'] === 'percent' ? $c['amount'] . '%' : format_price($c['amount']) . ' تومان';
        $usedBy = json_decode($c['used_by'] ?? '[]', true);
        $usedCount = is_array($usedBy) ? count($usedBy) : 0;
        $maxLabel = $c['max_uses'] > 0 ? $c['max_uses'] : '♾ نامحدود';
        $expiryLabel = $c['expires_at'] > 0 ? jdate('Y-m-d', $c['expires_at']) : '♾ بدون انقضا';

        $info = "🎟 *کد تخفیف #{$cid}*\n\n"
            . "📝 کد: `{$c['code']}`\n"
            . "📊 نوع: {$typeLabel}\n"
            . "💰 مقدار: {$amountLabel}\n"
            . "👥 استفاده: {$usedCount} / {$maxLabel}\n"
            . "📅 انقضا: {$expiryLabel}\n"
            . "📊 وضعیت: {$statusIcon}";

        $keys = [
            [['text' => ($c['active'] ? '🔴 غیرفعال کردن' : '🟢 فعال کردن'), 'callback_data' => 'toggleDiscount' . $cid]],
            [['text' => '🗑 حذف کد', 'callback_data' => 'deleteDiscount' . $cid]],
            [['text' => $btn['go_back'], 'callback_data' => 'discountList']],
        ];
        tg_edit($msgId, $info, json_encode(['inline_keyboard' => $keys]));
    }
}

// ── Toggle Discount Active ──────────────────────────────────────
if (preg_match('/^toggleDiscount(\d+)$/', $data, $m)) {
    $cid = (int) $m[1];
    $c = esi_fetch_one($db, "SELECT `active` FROM `esi_coupons` WHERE `id` = ?", 'i', $cid);
    if ($c) {
        $newState = $c['active'] ? 0 : 1;
        esi_execute($db, "UPDATE `esi_coupons` SET `active` = ? WHERE `id` = ?", 'ii', $newState, $cid);
        tg_alert($newState ? '✅ کد فعال شد.' : '🔴 کد غیرفعال شد.');
    }
}

// ── Delete Discount (Confirm) ───────────────────────────────────
if (preg_match('/^deleteDiscount(\d+)$/', $data, $m)) {
    $cid = (int) $m[1];
    $keys = json_encode(['inline_keyboard' => [
        [
            ['text' => '✅ بله، حذف شود', 'callback_data' => 'confirmDeleteDisc' . $cid],
            ['text' => '❌ خیر', 'callback_data' => 'viewDiscount' . $cid],
        ],
    ]]);
    tg_edit($msgId, "⚠️ آیا از حذف کد تخفیف #{$cid} مطمئن هستید؟", $keys);
}
if (preg_match('/^confirmDeleteDisc(\d+)$/', $data, $m)) {
    $cid = (int) $m[1];
    esi_execute($db, "DELETE FROM `esi_coupons` WHERE `id` = ?", 'i', $cid);
    tg_alert('✅ کد تخفیف حذف شد.');

    // Return to list
    $coupons = esi_fetch_all($db, "SELECT * FROM `esi_coupons` ORDER BY `id` DESC");
    $keys = [];
    foreach ($coupons as $c) {
        $status = $c['active'] ? '🟢' : '🔴';
        $typeLabel = $c['type'] === 'percent' ? $c['amount'] . '%' : format_price($c['amount']) . ' T';
        $keys[] = [['text' => "{$status} {$c['code']} ({$typeLabel})", 'callback_data' => 'viewDiscount' . $c['id']]];
    }
    $keys[] = [['text' => '➕ افزودن کد تخفیف', 'callback_data' => 'addDiscount']];
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
    tg_edit($msgId, '🎟 مدیریت کدهای تخفیف:', json_encode(['inline_keyboard' => $keys]));
}

// ── Cancel steps ────────────────────────────────────────────────
if (preg_match('/^addDisc/', $step) && $text === $btn['cancel']) {
    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');
    tg_send($msg['operation_cancelled'] ?? '❌ عملیات لغو شد.', $removeKeyboard);
}

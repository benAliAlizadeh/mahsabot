<?php
/**
 * MahsaBot - Group / Category Management Handler
 * Admin-only: CRUD for server groups (esi_groups)
 *
 * @package MahsaBot\Handlers
 */

if (!$isAdmin) return;

// ── Category List ───────────────────────────────────────────────
if ($data === 'groupSettings' || $data === 'categoryList') {
    $groups = esi_fetch_all($db,
        "SELECT g.*, ni.title as node_title, ni.flag FROM `esi_groups` g
         LEFT JOIN `esi_node_info` ni ON g.`node_id` = ni.`id`
         ORDER BY g.`sort_order` ASC, g.`id` ASC"
    );
    $keys = [];
    foreach ($groups as $g) {
        $status = $g['active'] ? '🟢' : '🔴';
        $flag = $g['flag'] ?? '🌐';
        $keys[] = [['text' => "{$status} {$flag} {$g['title']}", 'callback_data' => 'viewCategory' . $g['id']]];
    }
    $keys[] = [['text' => '➕ افزودن گروه', 'callback_data' => 'addCategory']];
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
    tg_edit($msgId, '📂 مدیریت گروه‌ها:', json_encode(['inline_keyboard' => $keys]));
}

// ── Add Category: Select Node ───────────────────────────────────
if ($data === 'addCategory') {
    $nodes = esi_fetch_all($db, "SELECT * FROM `esi_node_info` WHERE `active` = 1 ORDER BY `id` ASC");
    if (empty($nodes)) {
        tg_alert('❌ ابتدا یک سرور فعال اضافه کنید.');
    } else {
        $keys = [];
        foreach ($nodes as $n) {
            $keys[] = [['text' => "{$n['flag']} {$n['title']}", 'callback_data' => 'addCatNode' . $n['id']]];
        }
        $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'categoryList']];
        tg_edit($msgId, '🖥 سرور مورد نظر برای گروه جدید را انتخاب کنید:', json_encode(['inline_keyboard' => $keys]));
    }
}

// ── Add Category: Enter Title ───────────────────────────────────
if (preg_match('/^addCatNode(\d+)$/', $data, $m)) {
    $nodeId = (int) $m[1];
    $temp = ['node_id' => $nodeId];
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'addCatTitle');
    tg_delete();
    tg_send('📝 عنوان گروه جدید را وارد کنید:', $cancelKeyboard);
}

// ── Add Category: Save ──────────────────────────────────────────
if ($step === 'addCatTitle' && $text !== $btn['cancel']) {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $nodeId = (int) ($temp['node_id'] ?? 0);
    if (!$nodeId) {
        tg_send('❌ سرور انتخاب نشده. دوباره تلاش کنید.');
        esi_set_step($db, $fromId, 'idle');
    } else {
        $maxSort = esi_fetch_one($db, "SELECT COALESCE(MAX(`sort_order`), 0) + 1 as next_sort FROM `esi_groups`");
        $sortOrder = (int) ($maxSort['next_sort'] ?? 1);

        esi_execute($db,
            "INSERT INTO `esi_groups` (`node_id`, `title`, `active`, `sort_order`) VALUES (?, ?, 1, ?)",
            'isi', $nodeId, $text, $sortOrder
        );
        esi_set_step($db, $fromId, 'idle');
        esi_set_temp($db, $fromId, '');
        tg_send('✅ گروه جدید اضافه شد.', $removeKeyboard);

        // Refresh list
        $groups = esi_fetch_all($db,
            "SELECT g.*, ni.title as node_title, ni.flag FROM `esi_groups` g
             LEFT JOIN `esi_node_info` ni ON g.`node_id` = ni.`id`
             ORDER BY g.`sort_order` ASC, g.`id` ASC"
        );
        $keys = [];
        foreach ($groups as $g) {
            $status = $g['active'] ? '🟢' : '🔴';
            $flag = $g['flag'] ?? '🌐';
            $keys[] = [['text' => "{$status} {$flag} {$g['title']}", 'callback_data' => 'viewCategory' . $g['id']]];
        }
        $keys[] = [['text' => '➕ افزودن گروه', 'callback_data' => 'addCategory']];
        $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
        tg_send('📂 مدیریت گروه‌ها:', json_encode(['inline_keyboard' => $keys]));
    }
}

// ── View Category ───────────────────────────────────────────────
if (preg_match('/^viewCategory(\d+)$/', $data, $m)) {
    $gid = (int) $m[1];
    $g = esi_fetch_one($db,
        "SELECT g.*, ni.title as node_title, ni.flag FROM `esi_groups` g
         LEFT JOIN `esi_node_info` ni ON g.`node_id` = ni.`id`
         WHERE g.`id` = ?", 'i', $gid
    );
    if (!$g) {
        tg_alert('❌ گروه یافت نشد.');
    } else {
        $statusIcon = $g['active'] ? '🟢 فعال' : '🔴 غیرفعال';
        $pkgCount = esi_fetch_one($db, "SELECT COUNT(*) as cnt FROM `esi_packages` WHERE `group_id` = ?", 'i', $gid)['cnt'] ?? 0;

        $info = "📂 *گروه #{$gid}*\n\n"
            . "📝 عنوان: {$g['title']}\n"
            . "🖥 سرور: " . ($g['flag'] ?? '') . " " . ($g['node_title'] ?? '-') . "\n"
            . "📊 وضعیت: {$statusIcon}\n"
            . "🔢 ترتیب: {$g['sort_order']}\n"
            . "📦 تعداد پلن‌ها: {$pkgCount}";

        $keys = [
            [['text' => '✏️ ویرایش عنوان', 'callback_data' => 'editCatTitle' . $gid]],
            [['text' => '🔢 تغییر ترتیب', 'callback_data' => 'sortCategory' . $gid]],
            [['text' => ($g['active'] ? '🔴 غیرفعال کردن' : '🟢 فعال کردن'), 'callback_data' => 'toggleCategory' . $gid]],
            [['text' => '📦 مدیریت پلن‌ها', 'callback_data' => 'planList' . $gid]],
            [['text' => '🗑 حذف گروه', 'callback_data' => 'deleteCategory' . $gid]],
            [['text' => $btn['go_back'], 'callback_data' => 'categoryList']],
        ];
        tg_edit($msgId, $info, json_encode(['inline_keyboard' => $keys]));
    }
}

// ── Edit Category Title ─────────────────────────────────────────
if (preg_match('/^editCatTitle(\d+)$/', $data, $m)) {
    $gid = (int) $m[1];
    tg_delete();
    tg_send("📝 عنوان جدید گروه #{$gid} را وارد کنید:", $cancelKeyboard);
    esi_set_step($db, $fromId, 'editCatTitle_' . $gid);
}
if (preg_match('/^editCatTitle_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    $gid = (int) $m[1];
    esi_execute($db, "UPDATE `esi_groups` SET `title` = ? WHERE `id` = ?", 'si', $text, $gid);
    tg_send('✅ عنوان بروزرسانی شد.', $removeKeyboard);
    esi_set_step($db, $fromId, 'idle');
}

// ── Toggle Category Active ──────────────────────────────────────
if (preg_match('/^toggleCategory(\d+)$/', $data, $m)) {
    $gid = (int) $m[1];
    $g = esi_fetch_one($db, "SELECT `active` FROM `esi_groups` WHERE `id` = ?", 'i', $gid);
    if ($g) {
        $newState = $g['active'] ? 0 : 1;
        esi_execute($db, "UPDATE `esi_groups` SET `active` = ? WHERE `id` = ?", 'ii', $newState, $gid);
        tg_alert($newState ? '✅ گروه فعال شد.' : '🔴 گروه غیرفعال شد.');
    }
    // Re-trigger viewCategory
    $data = 'viewCategory' . $gid;
    $g = esi_fetch_one($db,
        "SELECT g.*, ni.title as node_title, ni.flag FROM `esi_groups` g
         LEFT JOIN `esi_node_info` ni ON g.`node_id` = ni.`id`
         WHERE g.`id` = ?", 'i', $gid
    );
    if ($g) {
        $statusIcon = $g['active'] ? '🟢 فعال' : '🔴 غیرفعال';
        $pkgCount = esi_fetch_one($db, "SELECT COUNT(*) as cnt FROM `esi_packages` WHERE `group_id` = ?", 'i', $gid)['cnt'] ?? 0;
        $info = "📂 *گروه #{$gid}*\n\n📝 عنوان: {$g['title']}\n🖥 سرور: " . ($g['flag'] ?? '') . " " . ($g['node_title'] ?? '-') . "\n📊 وضعیت: {$statusIcon}\n🔢 ترتیب: {$g['sort_order']}\n📦 تعداد پلن‌ها: {$pkgCount}";
        $keys = [
            [['text' => '✏️ ویرایش عنوان', 'callback_data' => 'editCatTitle' . $gid]],
            [['text' => '🔢 تغییر ترتیب', 'callback_data' => 'sortCategory' . $gid]],
            [['text' => ($g['active'] ? '🔴 غیرفعال کردن' : '🟢 فعال کردن'), 'callback_data' => 'toggleCategory' . $gid]],
            [['text' => '📦 مدیریت پلن‌ها', 'callback_data' => 'planList' . $gid]],
            [['text' => '🗑 حذف گروه', 'callback_data' => 'deleteCategory' . $gid]],
            [['text' => $btn['go_back'], 'callback_data' => 'categoryList']],
        ];
        tg_edit($msgId, $info, json_encode(['inline_keyboard' => $keys]));
    }
}

// ── Delete Category (Confirm) ───────────────────────────────────
if (preg_match('/^deleteCategory(\d+)$/', $data, $m)) {
    $gid = (int) $m[1];
    $keys = json_encode(['inline_keyboard' => [
        [
            ['text' => '✅ بله، حذف شود', 'callback_data' => 'confirmDeleteCat' . $gid],
            ['text' => '❌ خیر', 'callback_data' => 'viewCategory' . $gid],
        ],
    ]]);
    tg_edit($msgId, "⚠️ آیا از حذف گروه #{$gid} مطمئن هستید؟\nپلن‌های مرتبط نیز حذف خواهند شد.", $keys);
}
if (preg_match('/^confirmDeleteCat(\d+)$/', $data, $m)) {
    $gid = (int) $m[1];
    esi_execute($db, "DELETE FROM `esi_packages` WHERE `group_id` = ?", 'i', $gid);
    esi_execute($db, "DELETE FROM `esi_groups` WHERE `id` = ?", 'i', $gid);
    tg_alert('✅ گروه حذف شد.');

    // Return to category list
    $groups = esi_fetch_all($db,
        "SELECT g.*, ni.flag FROM `esi_groups` g
         LEFT JOIN `esi_node_info` ni ON g.`node_id` = ni.`id`
         ORDER BY g.`sort_order` ASC, g.`id` ASC"
    );
    $keys = [];
    foreach ($groups as $g) {
        $status = $g['active'] ? '🟢' : '🔴';
        $flag = $g['flag'] ?? '🌐';
        $keys[] = [['text' => "{$status} {$flag} {$g['title']}", 'callback_data' => 'viewCategory' . $g['id']]];
    }
    $keys[] = [['text' => '➕ افزودن گروه', 'callback_data' => 'addCategory']];
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
    tg_edit($msgId, '📂 مدیریت گروه‌ها:', json_encode(['inline_keyboard' => $keys]));
}

// ── Sort Category ───────────────────────────────────────────────
if (preg_match('/^sortCategory(\d+)$/', $data, $m)) {
    $gid = (int) $m[1];
    tg_delete();
    tg_send("🔢 ترتیب نمایش جدید گروه #{$gid} را وارد کنید (عدد):", $cancelKeyboard);
    esi_set_step($db, $fromId, 'sortCategory_' . $gid);
}
if (preg_match('/^sortCategory_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    $gid = (int) $m[1];
    if (is_numeric($text)) {
        esi_execute($db, "UPDATE `esi_groups` SET `sort_order` = ? WHERE `id` = ?", 'ii', (int) $text, $gid);
        tg_send('✅ ترتیب بروزرسانی شد.', $removeKeyboard);
        esi_set_step($db, $fromId, 'idle');
    } else {
        tg_send($msg['number_only'] ?? '❌ لطفاً فقط عدد وارد کنید.');
    }
}

// ── Cancel steps ────────────────────────────────────────────────
if (preg_match('/^(addCat|editCat|sortCategory)/', $step) && $text === $btn['cancel']) {
    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');
    tg_send($msg['operation_cancelled'] ?? '❌ عملیات لغو شد.', $removeKeyboard);
}

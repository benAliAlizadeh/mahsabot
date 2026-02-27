<?php
/**
 * MahsaBot - Support Ticket System Handler
 * Users open / reply / close tickets; admins manage from admin panel.
 *
 * @package MahsaBot\Handlers
 */

// ── Ticket Menu (User) ─────────────────────────────────────────
if ($data === 'ticketMenu' || $data === 'myTickets') {
    $tickets = esi_fetch_all($db,
        "SELECT * FROM `esi_tickets` WHERE `member_id` = ? ORDER BY `id` DESC LIMIT 20",
        'i', $fromId
    );
    $keys = [];
    foreach ($tickets as $t) {
        $statusEmoji = match ($t['status']) {
            'open'   => '🟢',
            'closed' => '🔴',
            default  => '🟡',
        };
        $keys[] = [['text' => "{$statusEmoji} #{$t['id']} - {$t['subject']}", 'callback_data' => 'viewTicket' . $t['id']]];
    }
    $keys[] = [['text' => '📩 ارسال تیکت جدید', 'callback_data' => 'openTicket']];
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'mainMenu']];
    tg_edit($msgId, '🎫 تیکت‌های پشتیبانی:', json_encode(['inline_keyboard' => $keys]));
}

// ── Open Ticket: Step 1 - Subject ───────────────────────────────
if ($data === 'openTicket') {
    tg_delete();
    tg_send('📝 موضوع تیکت را وارد کنید:', $cancelKeyboard);
    esi_set_step($db, $fromId, 'enterTicketSubject');
    esi_set_temp($db, $fromId, '{}');
}

if ($step === 'enterTicketSubject' && $text !== $btn['cancel']) {
    $temp = ['subject' => $text];
    esi_set_temp($db, $fromId, json_encode($temp));
    esi_set_step($db, $fromId, 'enterTicketMessage');
    tg_send('✉️ پیام خود را بنویسید:');
}

// ── Open Ticket: Step 2 - Message → Create ──────────────────────
if ($step === 'enterTicketMessage' && $text !== $btn['cancel']) {
    $temp = json_decode($member['temp_data'] ?? '{}', true);
    $subject = $temp['subject'] ?? 'بدون موضوع';

    // Create ticket
    esi_execute($db,
        "INSERT INTO `esi_tickets` (`member_id`, `subject`, `status`, `created_at`) VALUES (?, ?, 'open', ?)",
        'isi', $fromId, $subject, time()
    );
    $ticketId = esi_last_id($db);

    // First message
    esi_execute($db,
        "INSERT INTO `esi_ticket_messages` (`ticket_id`, `sender_id`, `message`, `message_type`, `tg_message_id`, `created_at`)
         VALUES (?, ?, ?, 'text', ?, ?)",
        'iisii', $ticketId, $fromId, $text, $msgId, time()
    );

    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');
    tg_send("✅ تیکت #{$ticketId} ثبت شد.\nپشتیبانی به‌زودی پاسخ خواهد داد.", $removeKeyboard);

    // Notify admins
    $admins = esi_fetch_all($db, "SELECT `tg_id` FROM `esi_members` WHERE `is_admin` = 1");
    $adminKeys = json_encode(['inline_keyboard' => [
        [['text' => '💬 پاسخ', 'callback_data' => 'replyTicket' . $ticketId]],
        [['text' => '🔴 بستن', 'callback_data' => 'closeTicket' . $ticketId]],
    ]]);
    $notifyText = "🎫 *تیکت جدید #{$ticketId}*\n\n"
        . "👤 کاربر: {$firstName} (`{$fromId}`)\n"
        . "📝 موضوع: {$subject}\n"
        . "✉️ پیام: {$text}";
    foreach ($admins as $adm) {
        tg_send($notifyText, $adminKeys, 'MarkDown', $adm['tg_id']);
    }
    // Also notify super admin
    if (!in_array(ESI_ADMIN_ID, array_column($admins, 'tg_id'))) {
        tg_send($notifyText, $adminKeys, 'MarkDown', ESI_ADMIN_ID);
    }
}

// ── View Ticket (User or Admin) ─────────────────────────────────
if (preg_match('/^viewTicket(\d+)$/', $data, $m)) {
    $tid = (int) $m[1];
    $ticket = esi_fetch_one($db, "SELECT * FROM `esi_tickets` WHERE `id` = ?", 'i', $tid);
    if (!$ticket) {
        tg_alert('❌ تیکت یافت نشد.');
    } elseif ($ticket['member_id'] != $fromId && !$isAdmin) {
        tg_alert('❌ دسترسی ندارید.');
    } else {
        $messages = esi_fetch_all($db,
            "SELECT * FROM `esi_ticket_messages` WHERE `ticket_id` = ? ORDER BY `id` DESC LIMIT 10",
            'i', $tid
        );
        $messages = array_reverse($messages);

        $statusLabel = match ($ticket['status']) {
            'open'   => '🟢 باز',
            'closed' => '🔴 بسته',
            default  => '🟡 ' . $ticket['status'],
        };

        $chatText = "🎫 *تیکت #{$tid}*\n📝 {$ticket['subject']}\n📊 {$statusLabel}\n\n";
        foreach ($messages as $msg_item) {
            $senderLabel = ($msg_item['sender_id'] == $ticket['member_id']) ? '👤 کاربر' : '🛡 پشتیبانی';
            $time = jdate('m/d H:i', $msg_item['created_at']);
            $chatText .= "{$senderLabel} [{$time}]:\n{$msg_item['message']}\n\n";
        }

        $keys = [];
        if ($ticket['status'] === 'open') {
            $keys[] = [['text' => '💬 پاسخ', 'callback_data' => 'replyTicket' . $tid]];
            $keys[] = [['text' => '🔴 بستن تیکت', 'callback_data' => 'closeTicket' . $tid]];
        }
        $backCb = $isAdmin ? 'adminTickets' : 'myTickets';
        $keys[] = [['text' => $btn['go_back'], 'callback_data' => $backCb]];
        tg_edit($msgId, $chatText, json_encode(['inline_keyboard' => $keys]));
    }
}

// ── Reply to Ticket ─────────────────────────────────────────────
if (preg_match('/^replyTicket(\d+)$/', $data, $m)) {
    $tid = (int) $m[1];
    $ticket = esi_fetch_one($db, "SELECT * FROM `esi_tickets` WHERE `id` = ? AND `status` = 'open'", 'i', $tid);
    if (!$ticket) {
        tg_alert('❌ تیکت بسته یا یافت نشد.');
    } elseif ($ticket['member_id'] != $fromId && !$isAdmin) {
        tg_alert('❌ دسترسی ندارید.');
    } else {
        tg_delete();
        tg_send("💬 پاسخ خود را برای تیکت #{$tid} بنویسید:", $cancelKeyboard);
        esi_set_step($db, $fromId, 'ticketReply_' . $tid);
    }
}

if (preg_match('/^ticketReply_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    $tid = (int) $m[1];
    $ticket = esi_fetch_one($db, "SELECT * FROM `esi_tickets` WHERE `id` = ? AND `status` = 'open'", 'i', $tid);
    if (!$ticket) {
        tg_send('❌ تیکت بسته شده.');
        esi_set_step($db, $fromId, 'idle');
    } else {
        // Insert message
        esi_execute($db,
            "INSERT INTO `esi_ticket_messages` (`ticket_id`, `sender_id`, `message`, `message_type`, `tg_message_id`, `created_at`)
             VALUES (?, ?, ?, 'text', ?, ?)",
            'iisii', $tid, $fromId, $text, $msgId, time()
        );

        esi_set_step($db, $fromId, 'idle');

        $replyKeys = json_encode(['inline_keyboard' => [
            [['text' => '💬 پاسخ', 'callback_data' => 'replyTicket' . $tid]],
            [['text' => '🔴 بستن', 'callback_data' => 'closeTicket' . $tid]],
        ]]);

        if ($fromId == $ticket['member_id']) {
            // User replied → notify admins
            tg_send("✅ پاسخ شما ثبت شد.", $removeKeyboard);
            $notifyText = "💬 *پاسخ جدید تیکت #{$tid}*\n👤 کاربر: {$firstName}\n\n{$text}";
            $admins = esi_fetch_all($db, "SELECT `tg_id` FROM `esi_members` WHERE `is_admin` = 1");
            foreach ($admins as $adm) {
                tg_send($notifyText, $replyKeys, 'MarkDown', $adm['tg_id']);
            }
            if (!in_array(ESI_ADMIN_ID, array_column($admins, 'tg_id'))) {
                tg_send($notifyText, $replyKeys, 'MarkDown', ESI_ADMIN_ID);
            }
        } else {
            // Admin replied → notify user
            tg_send("✅ پاسخ ارسال شد.", $removeKeyboard);
            $notifyText = "💬 *پاسخ پشتیبانی تیکت #{$tid}*\n\n{$text}";
            $userKeys = json_encode(['inline_keyboard' => [
                [['text' => '💬 پاسخ', 'callback_data' => 'replyTicket' . $tid]],
                [['text' => '📋 مشاهده تیکت', 'callback_data' => 'viewTicket' . $tid]],
            ]]);
            tg_send($notifyText, $userKeys, 'MarkDown', $ticket['member_id']);
        }
    }
}

// ── Close Ticket ────────────────────────────────────────────────
if (preg_match('/^closeTicket(\d+)$/', $data, $m)) {
    $tid = (int) $m[1];
    $ticket = esi_fetch_one($db, "SELECT * FROM `esi_tickets` WHERE `id` = ?", 'i', $tid);
    if (!$ticket) {
        tg_alert('❌ تیکت یافت نشد.');
    } elseif ($ticket['member_id'] != $fromId && !$isAdmin) {
        tg_alert('❌ دسترسی ندارید.');
    } else {
        esi_execute($db, "UPDATE `esi_tickets` SET `status` = 'closed' WHERE `id` = ?", 'i', $tid);
        tg_alert('✅ تیکت بسته شد.');

        // Notify the other party
        if ($fromId == $ticket['member_id']) {
            $notifyText = "🔴 تیکت #{$tid} توسط کاربر بسته شد.";
            $admins = esi_fetch_all($db, "SELECT `tg_id` FROM `esi_members` WHERE `is_admin` = 1");
            foreach ($admins as $adm) {
                tg_send($notifyText, null, 'MarkDown', $adm['tg_id']);
            }
        } else {
            tg_send("🔴 تیکت #{$tid} توسط پشتیبانی بسته شد.", null, 'MarkDown', $ticket['member_id']);
        }

        // Refresh view
        $keys = json_encode(['inline_keyboard' => [
            [['text' => $btn['go_back'], 'callback_data' => $isAdmin ? 'adminTickets' : 'myTickets']],
        ]]);
        tg_edit($msgId, "🔴 تیکت #{$tid} - *بسته شد*", $keys);
    }
}

// ── Admin: All Open Tickets ─────────────────────────────────────
if ($data === 'adminTickets' && $isAdmin) {
    $tickets = esi_fetch_all($db,
        "SELECT t.*, m.display_name FROM `esi_tickets` t
         LEFT JOIN `esi_members` m ON t.`member_id` = m.`tg_id`
         WHERE t.`status` = 'open'
         ORDER BY t.`id` DESC LIMIT 30"
    );
    $keys = [];
    if (empty($tickets)) {
        $keys[] = [['text' => '📭 تیکت بازی وجود ندارد', 'callback_data' => 'noop']];
    } else {
        foreach ($tickets as $t) {
            $name = $t['display_name'] ?? $t['member_id'];
            $keys[] = [['text' => "🟢 #{$t['id']} - {$name}: {$t['subject']}", 'callback_data' => 'viewTicket' . $t['id']]];
        }
    }
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'adminPanel']];
    tg_edit($msgId, '🎫 تیکت‌های باز:', json_encode(['inline_keyboard' => $keys]));
}

// ── Ticket message in context (user sends text while in ticket step) ─
if (preg_match('/^ticketMsg_(\d+)$/', $step, $m) && $text !== $btn['cancel']) {
    // Alias for ticketReply flow
    $tid = (int) $m[1];
    $ticket = esi_fetch_one($db, "SELECT * FROM `esi_tickets` WHERE `id` = ? AND `status` = 'open'", 'i', $tid);
    if ($ticket) {
        esi_execute($db,
            "INSERT INTO `esi_ticket_messages` (`ticket_id`, `sender_id`, `message`, `message_type`, `tg_message_id`, `created_at`)
             VALUES (?, ?, ?, 'text', ?, ?)",
            'iisii', $tid, $fromId, $text, $msgId, time()
        );
        tg_send("✅ پیام ثبت شد.");

        $replyKeys = json_encode(['inline_keyboard' => [
            [['text' => '💬 پاسخ', 'callback_data' => 'replyTicket' . $tid]],
        ]]);
        if ($fromId == $ticket['member_id']) {
            $admins = esi_fetch_all($db, "SELECT `tg_id` FROM `esi_members` WHERE `is_admin` = 1");
            foreach ($admins as $adm) {
                tg_send("💬 پیام جدید تیکت #{$tid} از {$firstName}:\n{$text}", $replyKeys, 'MarkDown', $adm['tg_id']);
            }
        } else {
            tg_send("💬 پیام جدید تیکت #{$tid} از پشتیبانی:\n{$text}", $replyKeys, 'MarkDown', $ticket['member_id']);
        }
    }
    esi_set_step($db, $fromId, 'idle');
}

// ── Cancel steps ────────────────────────────────────────────────
if (preg_match('/^(enterTicket|ticketReply|ticketMsg)/', $step) && $text === $btn['cancel']) {
    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');
    tg_send($msg['operation_cancelled'] ?? '❌ عملیات لغو شد.', $removeKeyboard);
}

<?php
/**
 * MahsaBot - Middleware Functions
 * Handles spam protection, channel lock, phone verification
 * 
 * @package MahsaBot
 */

/**
 * Check if user is spamming (rate limiter)
 * Returns false if OK, or the unban timestamp if banned
 */
function check_spam(mysqli $db, int $userId): mixed {
    $member = esi_get_member($db, $userId);
    if (!$member) return false;
    
    $spamData = json_decode($member['spam_data'] ?? '{}', true) ?: [];
    $now = time();
    
    // Check if currently banned
    if (isset($spamData['banned_until']) && $now < $spamData['banned_until']) {
        return $spamData['banned_until'];
    }
    
    // Clean old entries (keep last 60 seconds)
    $hits = array_filter($spamData['hits'] ?? [], fn($t) => ($now - $t) < 60);
    $hits[] = $now;
    
    // If more than 40 requests per minute, ban for 24 hours
    if (count($hits) > 40) {
        $spamData = [
            'banned_until' => $now + 86400,
            'hits' => [],
        ];
        esi_update_member($db, $userId, 'spam_data', json_encode($spamData));
        return $spamData['banned_until'];
    }
    
    $spamData['hits'] = array_values($hits);
    esi_update_member($db, $userId, 'spam_data', json_encode($spamData));
    return false;
}

/**
 * Process channel join requirement
 * Returns true if user should be blocked (not joined)
 */
function enforce_channel_join(string $memberStatus, string $lockChannel, string $text): bool {
    global $btn, $msg;
    
    if (empty($lockChannel)) return false;
    if (in_array($memberStatus, ['kicked', 'left'])) {
        $channelClean = str_replace('@', '', $lockChannel);
        tg_send(
            str_replace('CHANNEL-ID', $lockChannel, $msg['join_channel_required']),
            json_encode(['inline_keyboard' => [
                [['text' => $btn['join_channel'], 'url' => "https://t.me/{$channelClean}"]],
                [['text' => $btn['joined_confirm'], 'callback_data' => 'verifyJoin' . $text]],
            ]]),
            'HTML'
        );
        return true;
    }
    return false;
}

/**
 * Process phone number requirement
 * Returns true if phone is needed and request should be blocked
 */
function enforce_phone_verification(mysqli $db, array $member, $update, string $requirePhone, string $requireIranPhone): bool {
    global $fromId, $btn, $msg, $text, $removeKeyboard;
    
    if ($requirePhone !== 'on') return false;
    if (!empty($member['phone'])) return false;
    
    // Check if contact was sent
    if (isset($update->message->contact)) {
        $contact = $update->message->contact;
        if ($contact->user_id != $fromId) {
            tg_send($msg['use_keyboard_buttons']);
            return true;
        }
        
        $phone = $contact->phone_number;
        if ($requireIranPhone === 'on') {
            if (!preg_match('/^(\+98|98|0098|09)\d+/', $phone)) {
                tg_send($msg['iran_number_only']);
                return true;
            }
        }
        
        esi_update_member($db, $fromId, 'phone', $phone);
        tg_send($msg['phone_verified'], $removeKeyboard);
        $text = '/start';
        return false;
    }
    
    // Ask for phone number
    tg_send($msg['send_phone_prompt'], json_encode([
        'keyboard' => [[['text' => $btn['share_phone'], 'request_contact' => true]]],
        'resize_keyboard' => true,
    ]));
    return true;
}

/**
 * Register new user if not exists
 */
function ensure_member_exists(mysqli $db, int $userId, string $name, string $username): array {
    $member = esi_get_member($db, $userId);
    if (!$member) {
        $name = !empty($name) ? $name : ' ';
        $username = !empty($username) ? $username : ' ';
        esi_create_member($db, $userId, $name, $username);
        $member = esi_get_member($db, $userId);
    }
    return $member;
}

/**
 * Process referral link (/start REFERRER_ID)
 */
function process_referral(mysqli $db, int $userId, string $text, array $member): void {
    global $msg;
    
    if (!preg_match('/^\/start (\d+)$/', $text, $match)) return;
    
    $referrerId = (int)$match[1];
    if ($referrerId <= 0 || $referrerId === $userId) return;
    if (!empty($member['referred_by'])) return;
    
    // Check if referrer exists
    $referrer = esi_get_member($db, $referrerId);
    if (!$referrer) return;
    
    // Update referral
    esi_update_member($db, $userId, 'referred_by', (string)$referrerId);
    
    // Notify referrer
    tg_send($msg['referral_joined'], null, null, $referrerId);
}

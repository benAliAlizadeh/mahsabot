<?php
/**
 * MahsaBot - Start & Main Menu Handler
 * 
 * @package MahsaBot
 */

// ── /start command ──────────────────────────────────────────────
if (preg_match('/^\/[Ss]tart/', $text) || $text === $btn['back_main'] || $data === 'mainMenu') {
    esi_set_step($db, $fromId, 'idle');
    esi_set_temp($db, $fromId, '');
    
    $mainKeys = build_main_keys($db, $member, $botOptions, $fromId, $btn);
    
    if (isset($data) && $data === 'mainMenu') {
        $res = tg_edit($msgId, $msg['welcome_message'], $mainKeys);
        if (!($res->ok ?? false)) {
            tg_send($msg['welcome_message'], $mainKeys);
        }
    } else {
        // Notify admin of new user
        if ($fromId != ESI_ADMIN_ID && empty($member['first_visit'])) {
            esi_update_member($db, $fromId, 'first_visit', 'yes');
            $adminKeys = json_encode(['inline_keyboard' => [
                [['text' => $btn['send_dm'], 'callback_data' => 'dmUser' . $fromId]]
            ]]);
            tg_send(
                fill_template($msg['new_user_alert'], [
                    'FULLNAME' => "<a href='tg://user?id={$fromId}'>{$firstName}</a>",
                    'USERNAME' => $username,
                    'USERID'   => $fromId,
                ]),
                $adminKeys, 'html', ESI_ADMIN_ID
            );
        }
        tg_send($msg['welcome_message'], $mainKeys);
    }
}

// ── Send message to user (admin) ────────────────────────────────
if (preg_match('/^dmUser(\d+)/', $data, $m) && $isAdmin && $text !== $btn['cancel']) {
    tg_edit($msgId, $msg['send_message_to_user']);
    esi_set_step($db, $fromId, $data);
}
if (preg_match('/^dmUser(\d+)/', $step, $m) && $isAdmin && $text !== $btn['cancel']) {
    tg_send($text, null, null, $m[1]);
    tg_send($msg['message_sent_to_user'], $removeKeyboard);
    tg_send($msg['admin_panel_title'], build_admin_keys());
    esi_set_step($db, $fromId, 'idle');
}

// ── My Profile ──────────────────────────────────────────────────
if ($data === 'myProfile') {
    $serviceCount = esi_count_subscriptions($db, $fromId);
    $joinDate = jdate('Y-m-d', $member['joined_at'] ?? time());
    $balance = format_price($member['balance'] ?? 0);
    
    $info = fill_template($msg['profile_info'], [
        'USER-ID'       => $fromId,
        'FULLNAME'      => $firstName,
        'USERNAME'      => $username,
        'BALANCE'       => $balance,
        'SERVICE-COUNT' => $serviceCount,
        'JOIN-DATE'     => $joinDate,
    ]);
    
    $keys = json_encode(['inline_keyboard' => [
        [['text' => $btn['go_back'], 'callback_data' => 'mainMenu']],
    ]]);
    tg_edit($msgId, $info, $keys, 'HTML');
}

// ── Invite Friends ──────────────────────────────────────────────
if ($data === 'inviteFriends') {
    $refCount = esi_fetch_one($db, 
        "SELECT COUNT(*) as cnt FROM `esi_members` WHERE `referred_by` = ?", 'i', $fromId
    )['cnt'] ?? 0;
    
    $inviteAmount = esi_get_options($db, 'REFERRAL_REWARD')['amount'] ?? 0;
    $totalEarning = $refCount * (int)$inviteAmount;
    $inviteLink = "https://t.me/" . ESI_BOT_USERNAME . "?start=" . $fromId;
    
    $info = fill_template($msg['invite_title'], [
        'INVITE-COUNT'   => $refCount,
        'INVITE-EARNING' => format_price($totalEarning),
        'INVITE-LINK'    => $inviteLink,
    ]);
    
    $keys = json_encode(['inline_keyboard' => [
        [['text' => $btn['go_back'], 'callback_data' => 'mainMenu']],
    ]]);
    tg_edit($msgId, $info, $keys);
}

// ── Applications / Connection Guide ─────────────────────────────
if ($data === 'appGuides') {
    $apps = esi_fetch_all($db, "SELECT * FROM `esi_applications` WHERE `active` = 1");
    $keys = [];
    foreach ($apps as $app) {
        $keys[] = [['text' => $app['title'], 'url' => $app['url']]];
    }
    $keys[] = [['text' => $btn['go_back'], 'callback_data' => 'mainMenu']];
    tg_edit($msgId, $msg['app_list_title'], json_encode(['inline_keyboard' => $keys]));
}

/**
 * Build main menu inline keyboard
 */
function build_main_keys(mysqli $db, array $member, array $opts, int $fromId, array $btn): string {
    $keys = [];
    
    // Agent-specific buttons
    if (($opts['agencyActive'] ?? 'off') === 'on' && ($member['is_agent'] ?? 0) == 1) {
        $keys[] = [['text' => $btn['agency_panel'], 'callback_data' => 'agencyPanel']];
        $keys[] = [
            ['text' => $btn['agent_single_buy'], 'callback_data' => 'agentSingleBuy'],
            ['text' => $btn['agent_bulk_buy'], 'callback_data' => 'agentBulkBuy'],
        ];
        $keys[] = [['text' => $btn['my_services'], 'callback_data' => 'agentServiceList']];
    } else {
        // Agency request button
        if (($opts['agencyActive'] ?? 'off') === 'on' && ($member['is_agent'] ?? 0) == 0) {
            $keys[] = [['text' => $btn['request_agency'], 'callback_data' => 'requestAgency']];
        }
        // Buy + My Services
        if (($opts['sellActive'] ?? 'on') === 'on' || $fromId == ESI_ADMIN_ID || ($member['is_admin'] ?? 0) == 1) {
            $keys[] = [
                ['text' => $btn['my_services'], 'callback_data' => 'myServices'],
                ['text' => $btn['buy_service'], 'callback_data' => 'buyService'],
            ];
        } else {
            $keys[] = [['text' => $btn['my_services'], 'callback_data' => 'myServices']];
        }
    }
    
    // Test account
    if (($opts['testAccount'] ?? 'off') === 'on') {
        $keys[] = [['text' => $btn['test_service'], 'callback_data' => 'getTestService']];
    }
    
    // Wallet
    $keys[] = [['text' => $btn['charge_wallet'], 'callback_data' => 'chargeWallet']];
    
    // Invite & Profile
    $keys[] = [
        ['text' => $btn['invite_friends'], 'callback_data' => 'inviteFriends'],
        ['text' => $btn['my_profile'], 'callback_data' => 'myProfile'],
    ];
    
    // Availability status
    $showShared = ($opts['sharedStatus'] ?? 'off') === 'on';
    $showDedicated = ($opts['dedicatedStatus'] ?? 'off') === 'on';
    if ($showShared && $showDedicated) {
        $keys[] = [
            ['text' => $btn['shared_status'], 'callback_data' => 'nodeStatus1'],
            ['text' => $btn['dedicated_status'], 'callback_data' => 'nodeStatus2'],
        ];
    } elseif ($showShared) {
        $keys[] = [['text' => $btn['shared_status'], 'callback_data' => 'nodeStatus1']];
    } elseif ($showDedicated) {
        $keys[] = [['text' => $btn['dedicated_status'], 'callback_data' => 'nodeStatus2']];
    }
    
    // Apps & Tickets
    $keys[] = [
        ['text' => $btn['app_guides'], 'callback_data' => 'appGuides'],
        ['text' => $btn['my_tickets'], 'callback_data' => 'ticketMenu'],
    ];
    
    // Config lookup
    if (($opts['searchActive'] ?? 'on') === 'on' || $fromId == ESI_ADMIN_ID || ($member['is_admin'] ?? 0) == 1) {
        $keys[] = [['text' => $btn['config_lookup'], 'callback_data' => 'configLookup']];
    }
    
    // Custom main buttons from DB
    $customBtns = esi_fetch_all($db, "SELECT * FROM `esi_options` WHERE `option_key` LIKE 'MAIN_BTN_%'");
    $temp = [];
    foreach ($customBtns as $cb) {
        $title = str_replace('MAIN_BTN_', '', $cb['option_key']);
        $temp[] = ['text' => $title, 'callback_data' => 'customBtn' . $cb['id']];
        if (count($temp) >= 2) {
            $keys[] = $temp;
            $temp = [];
        }
    }
    if (!empty($temp)) $keys[] = $temp;
    
    // Admin panel
    if ($fromId == ESI_ADMIN_ID || ($member['is_admin'] ?? 0) == 1) {
        $keys[] = [['text' => $btn['manage_bot'], 'callback_data' => 'adminPanel']];
    }
    
    return json_encode(['inline_keyboard' => $keys]);
}

/**
 * Build admin panel inline keyboard
 */
function build_admin_keys(): string {
    global $btn, $fromId;
    
    $keys = [
        [
            ['text' => $btn['bot_stats'], 'callback_data' => 'botStats'],
            ['text' => $btn['dm_user'], 'callback_data' => 'directMessage'],
        ],
        [['text' => $btn['user_info'], 'callback_data' => 'userLookup']],
    ];
    
    if ($fromId == ESI_ADMIN_ID) {
        $keys[] = [['text' => $btn['admin_list'], 'callback_data' => 'adminList']];
    }
    
    $keys = array_merge($keys, [
        [
            ['text' => $btn['add_balance'], 'callback_data' => 'addUserBalance'],
            ['text' => $btn['sub_balance'], 'callback_data' => 'subUserBalance'],
        ],
        [
            ['text' => $btn['create_bulk'], 'callback_data' => 'createBulkAccounts'],
            ['text' => $btn['gift_addon'], 'callback_data' => 'giftAddon'],
        ],
        [
            ['text' => $btn['block_user'], 'callback_data' => 'blockUser'],
            ['text' => $btn['unblock_user'], 'callback_data' => 'unblockUser'],
        ],
        [['text' => $btn['search_user_cfg'], 'callback_data' => 'searchUserConfig']],
        [['text' => $btn['node_settings'], 'callback_data' => 'nodeSettings']],
        [['text' => $btn['group_settings'], 'callback_data' => 'groupSettings']],
        [['text' => $btn['package_settings'], 'callback_data' => 'packageSettings']],
        [
            ['text' => $btn['coupon_settings'], 'callback_data' => 'couponSettings'],
            ['text' => $btn['button_manager'], 'callback_data' => 'buttonManager'],
        ],
        [
            ['text' => $btn['gateway_settings'], 'callback_data' => 'gatewaySettings'],
            ['text' => $btn['bot_settings'], 'callback_data' => 'botConfig'],
        ],
        [
            ['text' => $btn['tickets_admin'], 'callback_data' => 'adminTickets'],
            ['text' => $btn['broadcast_msg'], 'callback_data' => 'broadcastMsg'],
        ],
        [['text' => $btn['forward_all'], 'callback_data' => 'forwardAll']],
        [
            ['text' => $btn['agent_manager'], 'callback_data' => 'agentManager'],
            ['text' => $btn['rejected_agents'], 'callback_data' => 'rejectedAgents'],
        ],
        [['text' => $btn['back_main'], 'callback_data' => 'mainMenu']],
    ]);
    
    return json_encode(['inline_keyboard' => $keys]);
}

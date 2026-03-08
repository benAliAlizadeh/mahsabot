<?php
/**
 * MahsaBot - Inline Query Handler
 * Returns shortcut results and user subscription results.
 */

if (($updateType ?? '') !== 'inline_query') {
    return;
}

$inlineId = trim((string)($inlineQueryId ?? ''));
if ($inlineId === '') {
    return;
}

$query = trim((string)($inlineQueryText ?? ''));
$chatType = strtolower(trim((string)($inlineChatType ?? '')));
$isPrivateInline = in_array($chatType, ['private', 'sender'], true);
$botUrl = 'https://t.me/' . ESI_BOT_USERNAME;
$openBotMarkup = ['inline_keyboard' => [[['text' => 'Open Bot', 'url' => $botUrl]]]];

$results = [];

// Inline shortcuts
$results[] = [
    'type' => 'article',
    'id' => 'sc_menu',
    'title' => 'Open Bot Menu',
    'description' => 'Open the bot in private chat.',
    'input_message_content' => [
        'message_text' => "Open the bot menu:\n{$botUrl}",
    ],
    'reply_markup' => $openBotMarkup,
];

$results[] = [
    'type' => 'article',
    'id' => 'sc_buy',
    'title' => 'Buy Service',
    'description' => 'Start a new purchase flow in the bot.',
    'input_message_content' => [
        'message_text' => "Buy service from bot:\n{$botUrl}",
    ],
    'reply_markup' => $openBotMarkup,
];

$results[] = [
    'type' => 'article',
    'id' => 'sc_support',
    'title' => 'Support Tickets',
    'description' => 'Open support section in private chat.',
    'input_message_content' => [
        'message_text' => "Open support in bot:\n{$botUrl}",
    ],
    'reply_markup' => $openBotMarkup,
];

if (!$isPrivateInline) {
    $results[] = [
        'type' => 'article',
        'id' => 'privacy_note',
        'title' => 'Open private chat for links',
        'description' => 'Subscription links are hidden in groups/channels.',
        'input_message_content' => [
            'message_text' => "For security, private subscription links are only available in private chat.\n{$botUrl}",
        ],
        'reply_markup' => $openBotMarkup,
    ];
    tg_answer_inline($inlineId, $results, 1, true);
    exit();
}

// Subscription search for private inline mode
if ($query === '') {
    $subs = esi_fetch_all($db,
        "SELECT s.id, s.config_name, s.connect_link, s.status, s.expires_at, n.title AS node_title, n.flag AS node_flag
         FROM esi_subscriptions s
         LEFT JOIN esi_node_info n ON n.id = s.node_id
         WHERE s.member_id = ?
         ORDER BY s.id DESC
         LIMIT 20",
        'i',
        $fromId
    );
} else {
    $like = '%' . $query . '%';
    $subs = esi_fetch_all($db,
        "SELECT s.id, s.config_name, s.connect_link, s.status, s.expires_at, n.title AS node_title, n.flag AS node_flag
         FROM esi_subscriptions s
         LEFT JOIN esi_node_info n ON n.id = s.node_id
         WHERE s.member_id = ?
           AND (s.config_name LIKE ? OR n.title LIKE ? OR CAST(s.id AS CHAR) = ?)
         ORDER BY s.id DESC
         LIMIT 20",
        'isss',
        $fromId,
        $like,
        $like,
        $query
    );
}

$maxServiceResults = 17; // keep total results under Telegram limits
$serviceCount = 0;
foreach ($subs as $sub) {
    if ($serviceCount >= $maxServiceResults) {
        break;
    }

    $serviceCount++;
    $subId = (int)($sub['id'] ?? 0);
    $configName = trim((string)($sub['config_name'] ?? '-'));
    $nodeTitle = trim((string)($sub['node_title'] ?? '-'));
    $nodeFlag = trim((string)($sub['node_flag'] ?? ''));
    $expiresAt = (int)($sub['expires_at'] ?? 0);
    $isExpired = ($expiresAt > 0 && $expiresAt < time());
    $statusIcon = ((int)($sub['status'] ?? 0) === 1 && !$isExpired) ? 'ACTIVE' : ($isExpired ? 'EXPIRED' : 'DISABLED');
    $link = trim((string)($sub['connect_link'] ?? ''));
    if ($link === '') {
        $link = 'Link is not cached. Open bot to refresh.';
    }

    $results[] = [
        'type' => 'article',
        'id' => 'sub_' . $subId,
        'title' => "#{$subId} {$configName}",
        'description' => "Node: {$nodeTitle}",
        'input_message_content' => [
            'message_text' => "Subscription #{$subId}\nStatus: {$statusIcon}\nNode: {$nodeFlag} {$nodeTitle}\nConfig: {$configName}\n\nLink:\n{$link}",
        ],
        'reply_markup' => $openBotMarkup,
    ];
}

if ($serviceCount === 0) {
    $results[] = [
        'type' => 'article',
        'id' => 'no_result',
        'title' => 'No matching subscriptions',
        'description' => 'Try another query or open the bot.',
        'input_message_content' => [
            'message_text' => "No matching subscriptions found.\nOpen bot: {$botUrl}",
        ],
        'reply_markup' => $openBotMarkup,
    ];
}

tg_answer_inline($inlineId, $results, 1, true);
exit();

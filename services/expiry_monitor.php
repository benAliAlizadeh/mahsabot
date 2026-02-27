<?php
/**
 * MahsaBot - Expiry Monitor Service
 * Warns users about expiring subscriptions and auto-disables expired ones
 * Cron: 0 */6 * * * php /path/to/mahsabot/services/expiry_monitor.php
 * 
 * @package MahsaBot
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/telegram.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../locale/messages.php';
require_once __DIR__ . '/../locale/buttons.php';
require_once __DIR__ . '/../panels/xui.php';
require_once __DIR__ . '/../panels/marzban.php';

$db = new mysqli(ESI_DB_HOST, ESI_DB_USER, ESI_DB_PASS, ESI_DB_NAME);
$db->set_charset('utf8mb4');
if ($db->connect_error) { error_log('MahsaBot ExpiryMonitor DB error: ' . $db->connect_error); exit(1); }

$GLOBALS['db'] = $db;

// Load options
$optRows = esi_fetch_all($db, "SELECT `option_key`, `option_value` FROM `esi_options`");
$botOptions = [];
foreach ($optRows as $row) { $botOptions[$row['option_key']] = $row['option_value']; }

$warnDays   = max(1, (int)($botOptions['warnDays'] ?? 2));
$autoDelete = ($botOptions['autoDelete'] ?? 'off') === 'on';
$now        = time();
$warnTime   = $now + ($warnDays * 86400);
$batchSize  = 100;

// ── Process Expired Subscriptions ───────────────────────────────
$expired = esi_fetch_all($db,
    "SELECT s.*, nc.panel_type FROM `esi_subscriptions` s
     LEFT JOIN `esi_node_config` nc ON nc.id = s.node_id
     WHERE s.`status` = 1 AND s.`expires_at` > 0 AND s.`expires_at` < ?
     LIMIT ?",
    'ii', $now, $batchSize
);

$expiredCount = 0;
foreach ($expired as $sub) {
    $memberId  = $sub['member_id'];
    $panelType = $sub['panel_type'] ?? 'sanaei';

    // Disable on panel
    $nodeConfig = esi_fetch_one($db, "SELECT * FROM `esi_node_config` WHERE `id` = ?", 'i', $sub['node_id']);
    if ($nodeConfig) {
        try {
            if ($panelType === 'marzban') {
                marzban_change_state($db, $nodeConfig, $sub['config_name'], false);
            } else {
                xui_change_state($db, $nodeConfig, $sub, false);
            }
        } catch (Exception $e) {
            error_log("MahsaBot ExpiryMonitor: Failed to disable {$sub['config_name']} on panel: " . $e->getMessage());
        }
    }

    // Update status in DB
    esi_execute($db, "UPDATE `esi_subscriptions` SET `status` = 0 WHERE `id` = ?", 'i', $sub['id']);

    // Notify user
    $renewKeys = json_encode(['inline_keyboard' => [
        [['text' => $btn['renew_service'] ?? '♻️ تمدید', 'callback_data' => 'renewSvc' . $sub['id']]],
    ]]);
    tg_send(
        "⏰ سرویس «{$sub['config_name']}» منقضی شده و غیرفعال شد.\nبرای تمدید از دکمه زیر استفاده کنید.",
        $renewKeys, null, $memberId
    );

    // Notify admin
    tg_send("🔴 سرویس منقضی: {$sub['config_name']} (کاربر: {$memberId})", null, null, ESI_ADMIN_ID);

    $expiredCount++;
    usleep(100000); // 100ms between notifications
}

// ── Warn About Expiring Soon ────────────────────────────────────
$expiring = esi_fetch_all($db,
    "SELECT s.* FROM `esi_subscriptions` s
     WHERE s.`status` = 1 AND s.`expires_at` > ? AND s.`expires_at` < ?
     LIMIT ?",
    'iii', $now, $warnTime, $batchSize
);

$warnCount = 0;
foreach ($expiring as $sub) {
    $remaining = $sub['expires_at'] - $now;
    $daysLeft  = max(1, (int)ceil($remaining / 86400));
    $memberId  = $sub['member_id'];

    $renewKeys = json_encode(['inline_keyboard' => [
        [['text' => $btn['renew_service'] ?? '♻️ تمدید', 'callback_data' => 'renewSvc' . $sub['id']]],
    ]]);

    tg_send(
        "⚠️ سرویس «{$sub['config_name']}» تا {$daysLeft} روز دیگر منقضی می‌شود.\nبرای تمدید اقدام کنید.",
        $renewKeys, null, $memberId
    );

    $warnCount++;
    usleep(100000);
}

// ── Auto-Delete If Enabled ──────────────────────────────────────
if ($autoDelete) {
    $deleteDays   = max(1, (int)($botOptions['autoDeleteDays'] ?? 3));
    $deleteThresh = $now - ($deleteDays * 86400);

    $toDelete = esi_fetch_all($db,
        "SELECT s.*, nc.panel_type FROM `esi_subscriptions` s
         LEFT JOIN `esi_node_config` nc ON nc.id = s.node_id
         WHERE s.`status` = 0 AND s.`expires_at` > 0 AND s.`expires_at` < ?
         LIMIT ?",
        'ii', $deleteThresh, $batchSize
    );

    foreach ($toDelete as $sub) {
        $nodeConfig = esi_fetch_one($db, "SELECT * FROM `esi_node_config` WHERE `id` = ?", 'i', $sub['node_id']);
        if ($nodeConfig) {
            try {
                if (($sub['panel_type'] ?? 'sanaei') === 'marzban') {
                    marzban_delete_user_account($db, $nodeConfig, $sub['config_name']);
                } else {
                    xui_delete_account($db, $nodeConfig, $sub);
                }
            } catch (Exception $e) {
                error_log("MahsaBot ExpiryMonitor: Auto-delete failed for {$sub['config_name']}: " . $e->getMessage());
            }
        }

        // Restore capacity
        esi_execute($db, "UPDATE `esi_node_info` SET `capacity` = `capacity` + 1 WHERE `id` = ?", 'i', $sub['node_id']);

        // Remove from DB
        esi_execute($db, "DELETE FROM `esi_subscriptions` WHERE `id` = ?", 'i', $sub['id']);
    }
}

error_log("MahsaBot ExpiryMonitor: Expired={$expiredCount}, Warned={$warnCount}");
$db->close();

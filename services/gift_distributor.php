<?php
/**
 * MahsaBot - Gift Distributor Service
 * Distributes balance/volume/day gifts to all users
 * Cron: */5 * * * * php /path/to/mahsabot/services/gift_distributor.php
 * 
 * @package MahsaBot
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/telegram.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../panels/xui.php';
require_once __DIR__ . '/../panels/marzban.php';

$db = new mysqli(ESI_DB_HOST, ESI_DB_USER, ESI_DB_PASS, ESI_DB_NAME);
$db->set_charset('utf8mb4');
if ($db->connect_error) { error_log('MahsaBot GiftDistributor DB error: ' . $db->connect_error); exit(1); }

$GLOBALS['db'] = $db;

// Get active gifts
$gifts = esi_fetch_all($db, "SELECT * FROM `esi_gifts` WHERE `active` = 1 ORDER BY `id` ASC LIMIT 5");

foreach ($gifts as $gift) {
    $giftId   = $gift['id'];
    $giftType = $gift['gift_type'] ?? 'balance';
    $amount   = (float)$gift['amount'];

    error_log("MahsaBot GiftDistributor: Processing gift #{$giftId} type={$giftType} amount={$amount}");

    switch ($giftType) {
        case 'balance':
            // Add balance to all users
            esi_execute($db, "UPDATE `esi_members` SET `balance` = `balance` + ?", 'i', (int)$amount);

            // Notify users in batches
            $offset = 0;
            $batch  = 100;
            while (true) {
                $members = esi_fetch_all($db,
                    "SELECT `tg_id` FROM `esi_members` ORDER BY `tg_id` LIMIT ? OFFSET ?",
                    'ii', $batch, $offset
                );
                if (empty($members)) break;

                foreach ($members as $m) {
                    tg_send("🎁 هدیه‌ای دریافت کردید!\n💰 " . format_price((int)$amount) . " تومان به کیف پول شما اضافه شد.", null, null, $m['tg_id']);
                    usleep(50000); // 50ms
                }
                $offset += $batch;
                sleep(2);
            }
            break;

        case 'volume':
            // Add volume to all active subscriptions
            $subs = esi_fetch_all($db,
                "SELECT s.*, nc.panel_type FROM `esi_subscriptions` s
                 LEFT JOIN `esi_node_config` nc ON nc.id = s.node_id
                 WHERE s.`status` = 1"
            );

            foreach ($subs as $sub) {
                $nodeConfig = esi_fetch_one($db, "SELECT * FROM `esi_node_config` WHERE `id` = ?", 'i', $sub['node_id']);
                if (!$nodeConfig) continue;

                try {
                    if (($sub['panel_type'] ?? 'sanaei') === 'marzban') {
                        marzban_edit_config($db, $nodeConfig, $sub['config_name'], 'add', 0, $amount);
                    } else {
                        xui_edit_traffic($db, $nodeConfig, $sub, 'add', 0, $amount);
                    }
                } catch (Exception $e) {
                    error_log("MahsaBot GiftDistributor: Volume add failed for {$sub['config_name']}: " . $e->getMessage());
                }
                usleep(200000);
            }

            // Notify users
            $notified = [];
            foreach ($subs as $sub) {
                if (!in_array($sub['member_id'], $notified)) {
                    tg_send("🎁 هدیه‌ای دریافت کردید!\n📦 {$amount} گیگابایت به سرویس‌های فعال شما اضافه شد.", null, null, $sub['member_id']);
                    $notified[] = $sub['member_id'];
                    usleep(50000);
                }
            }
            break;

        case 'day':
            // Add days to all active subscriptions
            $addSeconds = (int)$amount * 86400;
            $subs = esi_fetch_all($db,
                "SELECT s.*, nc.panel_type FROM `esi_subscriptions` s
                 LEFT JOIN `esi_node_config` nc ON nc.id = s.node_id
                 WHERE s.`status` = 1"
            );

            foreach ($subs as $sub) {
                $nodeConfig = esi_fetch_one($db, "SELECT * FROM `esi_node_config` WHERE `id` = ?", 'i', $sub['node_id']);
                if (!$nodeConfig) continue;

                try {
                    if (($sub['panel_type'] ?? 'sanaei') === 'marzban') {
                        marzban_edit_config($db, $nodeConfig, $sub['config_name'], 'add', (int)$amount, 0);
                    } else {
                        xui_edit_traffic($db, $nodeConfig, $sub, 'add', (int)$amount, 0);
                    }
                } catch (Exception $e) {
                    error_log("MahsaBot GiftDistributor: Day add failed for {$sub['config_name']}: " . $e->getMessage());
                }

                // Update expiry in DB
                esi_execute($db, "UPDATE `esi_subscriptions` SET `expires_at` = `expires_at` + ? WHERE `id` = ?", 'ii', $addSeconds, $sub['id']);
                usleep(200000);
            }

            $notified = [];
            foreach ($subs as $sub) {
                if (!in_array($sub['member_id'], $notified)) {
                    tg_send("🎁 هدیه‌ای دریافت کردید!\n📅 {$amount} روز به سرویس‌های فعال شما اضافه شد.", null, null, $sub['member_id']);
                    $notified[] = $sub['member_id'];
                    usleep(50000);
                }
            }
            break;
    }

    // Mark gift as processed
    esi_execute($db, "UPDATE `esi_gifts` SET `active` = 0 WHERE `id` = ?", 'i', $giftId);
    error_log("MahsaBot GiftDistributor: Gift #{$giftId} completed");
}

$db->close();

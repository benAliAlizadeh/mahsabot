<?php
/**
 * MahsaBot - Broadcast Service
 * Sends queued messages to all members
 * Cron: * * * * * php /path/to/mahsabot/services/broadcaster.php
 * 
 * @package MahsaBot
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/telegram.php';
require_once __DIR__ . '/../core/helpers.php';

$db = new mysqli(ESI_DB_HOST, ESI_DB_USER, ESI_DB_PASS, ESI_DB_NAME);
$db->set_charset('utf8mb4');
if ($db->connect_error) { error_log('MahsaBot Broadcaster DB error: ' . $db->connect_error); exit(1); }

$GLOBALS['db'] = $db;

$batchSize   = 50;
$sleepMs     = 1500000; // 1.5s in microseconds
$maxPerRun   = 500;

// Get pending broadcasts
$broadcasts = esi_fetch_all($db, "SELECT * FROM `esi_broadcast` WHERE `status` = 'pending' ORDER BY `id` ASC LIMIT 5");

foreach ($broadcasts as $bc) {
    $bcId       = $bc['id'];
    $lastId     = (int)$bc['last_processed_id'];
    $msgText    = $bc['message'];
    $msgType    = $bc['message_type'] ?? 'text';
    $tgMsgId    = (int)$bc['tg_message_id'];
    $processed  = 0;
    $success    = 0;
    $failed     = 0;

    while ($processed < $maxPerRun) {
        $members = esi_fetch_all($db,
            "SELECT `tg_id` FROM `esi_members` WHERE `tg_id` > ? ORDER BY `tg_id` ASC LIMIT ?",
            'ii', $lastId, $batchSize
        );

        if (empty($members)) {
            // All done
            esi_execute($db, "UPDATE `esi_broadcast` SET `status` = 'completed' WHERE `id` = ?", 'i', $bcId);
            break;
        }

        foreach ($members as $m) {
            $targetId = $m['tg_id'];
            $processed++;

            try {
                switch ($msgType) {
                    case 'forward':
                        $res = tg_forward($targetId, ESI_ADMIN_ID, $tgMsgId);
                        break;
                    case 'copy':
                        $res = tg_request('copyMessage', [
                            'chat_id'      => $targetId,
                            'from_chat_id' => ESI_ADMIN_ID,
                            'message_id'   => $tgMsgId,
                        ]);
                        break;
                    default:
                        $res = tg_send($msgText, null, 'HTML', $targetId);
                        break;
                }

                if ($res && isset($res['ok']) && $res['ok']) {
                    $success++;
                } else {
                    $failed++;
                }
            } catch (Exception $e) {
                $failed++;
                error_log("MahsaBot Broadcast #{$bcId} failed for {$targetId}: " . $e->getMessage());
            }

            $lastId = $targetId;
        }

        // Update progress
        esi_execute($db, "UPDATE `esi_broadcast` SET `last_processed_id` = ? WHERE `id` = ?", 'ii', $lastId, $bcId);

        // Rate limit
        usleep($sleepMs);
    }

    // If we hit maxPerRun, mark as still pending (will resume next cron run)
    if ($processed >= $maxPerRun) {
        error_log("MahsaBot Broadcast #{$bcId}: processed {$processed} (success:{$success}, failed:{$failed}), continuing next run");
    } else {
        error_log("MahsaBot Broadcast #{$bcId}: completed (success:{$success}, failed:{$failed})");
    }
}

$db->close();

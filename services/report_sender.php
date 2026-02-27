<?php
/**
 * MahsaBot - Report Sender Service
 * Sends periodic stats reports to admin
 * Cron: 0 8 * * * php /path/to/mahsabot/services/report_sender.php
 * 
 * @package MahsaBot
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/telegram.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../lib/jdf.php';

$db = new mysqli(ESI_DB_HOST, ESI_DB_USER, ESI_DB_PASS, ESI_DB_NAME);
$db->set_charset('utf8mb4');
if ($db->connect_error) { error_log('MahsaBot ReportSender DB error: ' . $db->connect_error); exit(1); }

$GLOBALS['db'] = $db;

$now        = time();
$todayStart = strtotime('today midnight');
$weekStart  = strtotime('monday this week midnight');
$monthStart = strtotime('first day of this month midnight');

// ── Gather Statistics ───────────────────────────────────────────
$totalMembers = (int)(esi_fetch_one($db, "SELECT COUNT(*) as cnt FROM `esi_members`")['cnt'] ?? 0);
$todayMembers = (int)(esi_fetch_one($db, "SELECT COUNT(*) as cnt FROM `esi_members` WHERE `joined_at` >= ?", 'i', $todayStart)['cnt'] ?? 0);
$weekMembers  = (int)(esi_fetch_one($db, "SELECT COUNT(*) as cnt FROM `esi_members` WHERE `joined_at` >= ?", 'i', $weekStart)['cnt'] ?? 0);

$activeSubs   = (int)(esi_fetch_one($db, "SELECT COUNT(*) as cnt FROM `esi_subscriptions` WHERE `status` = 1")['cnt'] ?? 0);
$totalSubs    = (int)(esi_fetch_one($db, "SELECT COUNT(*) as cnt FROM `esi_subscriptions`")['cnt'] ?? 0);

$todayRevenue = (int)(esi_fetch_one($db, "SELECT COALESCE(SUM(`amount`),0) as total FROM `esi_transactions` WHERE `status` = 'approved' AND `created_at` >= ?", 'i', $todayStart)['total'] ?? 0);
$weekRevenue  = (int)(esi_fetch_one($db, "SELECT COALESCE(SUM(`amount`),0) as total FROM `esi_transactions` WHERE `status` = 'approved' AND `created_at` >= ?", 'i', $weekStart)['total'] ?? 0);
$monthRevenue = (int)(esi_fetch_one($db, "SELECT COALESCE(SUM(`amount`),0) as total FROM `esi_transactions` WHERE `status` = 'approved' AND `created_at` >= ?", 'i', $monthStart)['total'] ?? 0);

$todaySales   = (int)(esi_fetch_one($db, "SELECT COUNT(*) as cnt FROM `esi_transactions` WHERE `status` = 'approved' AND `tx_type` = 'BUY_SUB' AND `created_at` >= ?", 'i', $todayStart)['cnt'] ?? 0);
$pendingTx    = (int)(esi_fetch_one($db, "SELECT COUNT(*) as cnt FROM `esi_transactions` WHERE `status` IN ('pending_receipt','pending_review','pending_tron','1')")['cnt'] ?? 0);
$openTickets  = (int)(esi_fetch_one($db, "SELECT COUNT(*) as cnt FROM `esi_tickets` WHERE `status` = 'open'")['cnt'] ?? 0);
$agentCount   = (int)(esi_fetch_one($db, "SELECT COUNT(*) as cnt FROM `esi_members` WHERE `is_agent` = 1")['cnt'] ?? 0);

// Jalali date
$jalaliDate = function_exists('jdate') ? jdate('Y/m/d', $now) : date('Y/m/d', $now);

// ── Build Report Message ────────────────────────────────────────
$report = "📊 *گزارش روزانه MahsaBot*\n"
    . "📅 تاریخ: {$jalaliDate}\n"
    . "━━━━━━━━━━━━━━━━━━━\n"
    . "\n"
    . "👥 *اعضا:*\n"
    . "├ کل: " . number_format($totalMembers) . "\n"
    . "├ امروز: {$todayMembers}\n"
    . "└ این هفته: {$weekMembers}\n"
    . "\n"
    . "📦 *سرویس‌ها:*\n"
    . "├ فعال: " . number_format($activeSubs) . "\n"
    . "└ کل: " . number_format($totalSubs) . "\n"
    . "\n"
    . "💰 *درآمد:*\n"
    . "├ امروز: " . format_price($todayRevenue) . " تومان\n"
    . "├ این هفته: " . format_price($weekRevenue) . " تومان\n"
    . "└ این ماه: " . format_price($monthRevenue) . " تومان\n"
    . "\n"
    . "🛒 *فروش امروز:* {$todaySales} سرویس\n"
    . "⏳ *تراکنش‌های معلق:* {$pendingTx}\n"
    . "🎫 *تیکت‌های باز:* {$openTickets}\n"
    . "👔 *نمایندگان:* {$agentCount}\n"
    . "\n"
    . "━━━━━━━━━━━━━━━━━━━\n"
    . "🤖 _MahsaBot Report Service_";

tg_send($report, null, 'MarkDown', ESI_ADMIN_ID);

error_log("MahsaBot ReportSender: Report sent. Members={$totalMembers}, Revenue={$todayRevenue}");

$db->close();

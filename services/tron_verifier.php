<?php
/**
 * MahsaBot - Tron (TRX) Payment Verifier
 * Verifies pending TRX transactions via TronGrid API
 * Cron: */2 * * * * php /path/to/mahsabot/services/tron_verifier.php
 * 
 * CRITICAL FIX: Original wizwiz had `if($success = "SUCCESS")` (assignment, always true).
 * This version uses `===` (strict comparison).
 * 
 * @package MahsaBot
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/telegram.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../locale/messages.php';

$db = new mysqli(ESI_DB_HOST, ESI_DB_USER, ESI_DB_PASS, ESI_DB_NAME);
$db->set_charset('utf8mb4');
if ($db->connect_error) { error_log('MahsaBot TronVerifier DB error: ' . $db->connect_error); exit(1); }

$GLOBALS['db'] = $db;

// Load payment keys from options
$optRows = esi_fetch_all($db, "SELECT `option_key`, `option_value` FROM `esi_options`");
$botOptions = [];
foreach ($optRows as $row) { $botOptions[$row['option_key']] = $row['option_value']; }
$tronWallet = $botOptions['tronwallet'] ?? '';

if (empty($tronWallet)) {
    error_log('MahsaBot TronVerifier: No tron wallet configured');
    exit(0);
}

// Get pending tron transactions (status='1' means TXID submitted)
$pending = esi_fetch_all($db,
    "SELECT * FROM `esi_transactions` WHERE `status` = '1' AND `tron_amount` > 0 ORDER BY `id` ASC LIMIT 50"
);

if (empty($pending)) { $db->close(); exit(0); }

foreach ($pending as $tx) {
    $txId       = $tx['id'];
    $txid       = trim($tx['gateway_ref']);
    $expected   = (float)$tx['tron_amount'];
    $memberId   = (int)$tx['member_id'];

    if (empty($txid) || !preg_match('/^[a-fA-F0-9]{64}$/', $txid)) {
        error_log("MahsaBot TronVerifier: Invalid TXID for tx #{$txId}");
        continue;
    }

    // Query TronGrid API
    $apiUrl  = "https://api.trongrid.io/v1/transactions/{$txid}";
    $apiResp = http_get($apiUrl, ['accept: application/json']);

    if (!$apiResp) {
        error_log("MahsaBot TronVerifier: API failed for tx #{$txId}, TXID: {$txid}");
        usleep(500000);
        continue;
    }

    $data = json_decode($apiResp, true);

    if (!$data || !isset($data['ret'][0]['contractRet'])) {
        error_log("MahsaBot TronVerifier: Invalid API response for tx #{$txId}");
        usleep(200000);
        continue;
    }

    // *** CRITICAL FIX: Use === not = ***
    $contractResult = $data['ret'][0]['contractRet'] ?? '';
    if ($contractResult !== 'SUCCESS') {
        esi_execute($db, "UPDATE `esi_transactions` SET `status` = 'failed' WHERE `id` = ?", 'i', $txId);
        tg_send("❌ تراکنش TRX شما تایید نشد.\nوضعیت: {$contractResult}", null, null, $memberId);
        error_log("MahsaBot TronVerifier: tx #{$txId} contractRet={$contractResult}");
        continue;
    }

    // Extract transfer details
    $contract  = $data['raw_data']['contract'][0] ?? null;
    $paramVal  = $contract['parameter']['value'] ?? [];
    $amountSun = (int)($paramVal['amount'] ?? 0);
    $amountTrx = $amountSun / 1000000;

    // Verify to_address - convert hex to base58
    $toAddrHex = $paramVal['to_address'] ?? '';
    $toAddr    = hex_to_base58($toAddrHex);

    // Validate amount (allow 0.5% tolerance for exchange rate fluctuation)
    $tolerance = $expected * 0.005;
    if ($amountTrx < ($expected - $tolerance)) {
        esi_execute($db, "UPDATE `esi_transactions` SET `status` = 'failed' WHERE `id` = ?", 'i', $txId);
        tg_send("❌ مبلغ واریزی ({$amountTrx} TRX) کمتر از مبلغ موردنیاز ({$expected} TRX) است.", null, null, $memberId);
        error_log("MahsaBot TronVerifier: tx #{$txId} amount mismatch: got {$amountTrx}, expected {$expected}");
        continue;
    }

    // Validate destination wallet
    if (!empty($toAddr) && strtolower($toAddr) !== strtolower($tronWallet)) {
        esi_execute($db, "UPDATE `esi_transactions` SET `status` = 'failed' WHERE `id` = ?", 'i', $txId);
        tg_send("❌ آدرس کیف پول مقصد اشتباه است.", null, null, $memberId);
        error_log("MahsaBot TronVerifier: tx #{$txId} wrong wallet: {$toAddr} != {$tronWallet}");
        continue;
    }

    // ✅ Payment verified!
    esi_execute($db, "UPDATE `esi_transactions` SET `status` = 'approved' WHERE `id` = ?", 'i', $txId);

    // Process based on transaction type
    $txType = $tx['tx_type'] ?? 'BUY_SUB';

    if ($txType === 'INCREASE_WALLET') {
        $amount = (int)$tx['amount'];
        esi_execute($db, "UPDATE `esi_members` SET `balance` = `balance` + ? WHERE `tg_id` = ?", 'ii', $amount, $memberId);
        tg_send("✅ پرداخت TRX تایید شد!\n💰 مبلغ " . format_price($amount) . " تومان به کیف پول شما اضافه شد.", null, null, $memberId);
    } else {
        // For subscription purchases, notify admin to process
        tg_send("✅ پرداخت TRX شما تایید شد! سرویس شما در حال آماده‌سازی است...", null, null, $memberId);

        // Notify admin
        $memberInfo = esi_get_member($db, $memberId);
        $adminMsg = "✅ تراکنش TRX تایید شد\n"
            . "👤 کاربر: " . ($memberInfo['display_name'] ?? $memberId) . "\n"
            . "💰 مبلغ: {$amountTrx} TRX\n"
            . "📋 TXID: `{$txid}`\n"
            . "🆔 تراکنش: #{$txId}";
        tg_send($adminMsg, null, 'MarkDown', ESI_ADMIN_ID);
    }

    usleep(200000); // 200ms rate limit
}

$db->close();

// ── Helper: Hex address to Base58 ───────────────────────────────
function hex_to_base58(string $hex): string {
    if (empty($hex)) return '';

    // Remove 0x prefix if present
    $hex = ltrim($hex, '0x');

    // Add Tron prefix if not present
    if (substr($hex, 0, 2) !== '41') {
        $hex = '41' . $hex;
    }

    $bin   = hex2bin($hex);
    $hash1 = hash('sha256', $bin, true);
    $hash2 = hash('sha256', $hash1, true);
    $check = substr($hash2, 0, 4);
    $bin   = $bin . $check;

    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $base     = strlen($alphabet);

    // Convert binary to decimal using GMP or BCMath
    if (function_exists('gmp_init')) {
        $num = gmp_init(bin2hex($bin), 16);
        $encoded = '';
        while (gmp_cmp($num, 0) > 0) {
            list($num, $rem) = gmp_div_qr($num, $base);
            $encoded = $alphabet[gmp_intval($rem)] . $encoded;
        }
    } elseif (function_exists('bcmul')) {
        $num = '0';
        $bytes = str_split(bin2hex($bin), 2);
        foreach ($bytes as $byte) {
            $num = bcmul($num, '256');
            $num = bcadd($num, (string)hexdec($byte));
        }
        $encoded = '';
        while (bccomp($num, '0') > 0) {
            $rem = bcmod($num, (string)$base);
            $num = bcdiv($num, (string)$base, 0);
            $encoded = $alphabet[(int)$rem] . $encoded;
        }
    } else {
        return ''; // No big number support
    }

    // Add leading 1s
    for ($i = 0; $i < strlen($bin) && $bin[$i] === "\x00"; $i++) {
        $encoded = '1' . $encoded;
    }

    return $encoded;
}

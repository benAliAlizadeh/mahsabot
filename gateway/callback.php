<?php
/**
 * MahsaBot - Payment Gateway Callback
 * Verifies payment and processes the order
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
if ($db->connect_error) { show_result(false, 'خطا در اتصال'); }

$GLOBALS['db'] = $db;

$gateway = trim($_GET['gateway'] ?? '');
$refCode = trim($_GET['ref'] ?? '');

if (empty($gateway) || empty($refCode)) { show_result(false, 'پارامترهای نامعتبر'); }

// Load options
$optRows = esi_fetch_all($db, "SELECT `option_key`, `option_value` FROM `esi_options`");
$opts = [];
foreach ($optRows as $r) { $opts[$r['option_key']] = $r['option_value']; }

// Look up transaction
$tx = esi_fetch_one($db, "SELECT * FROM `esi_transactions` WHERE `ref_code` = ?", 's', $refCode);
if (!$tx) { show_result(false, 'تراکنش یافت نشد'); }
if ($tx['status'] === 'approved') { show_result(true, 'تراکنش قبلاً پردازش شده'); }

$amount = (int)$tx['amount'];
$rials  = $amount * 10;
$verified = false;

switch ($gateway) {
    // ── Zarinpal ────────────────────────────────────────────────
    case 'zarinpal':
        $authority = $_GET['Authority'] ?? '';
        $status    = $_GET['Status'] ?? '';

        if ($status !== 'OK' || empty($authority)) { show_result(false, 'پرداخت ناموفق بود'); }

        try {
            $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', [
                'encoding'   => 'UTF-8',
                'cache_wsdl' => WSDL_CACHE_NONE,
            ]);
            $result = $client->PaymentVerification([
                'MerchantID' => $opts['zarinpalKey'] ?? '',
                'Authority'  => $authority,
                'Amount'     => $rials,
            ]);

            if ($result->Status == 100 || $result->Status == 101) {
                $verified = true;
                esi_execute($db, "UPDATE `esi_transactions` SET `gateway_ref` = ? WHERE `id` = ?",
                    'si', 'ZP-' . ($result->RefID ?? ''), $tx['id']);
            }
        } catch (Exception $e) {
            error_log('MahsaBot Zarinpal verify error: ' . $e->getMessage());
        }
        break;

    // ── NextPay ─────────────────────────────────────────────────
    case 'nextpay':
        $transId = $_POST['trans_id'] ?? $_GET['trans_id'] ?? $tx['gateway_ref'] ?? '';
        if (empty($transId)) { show_result(false, 'شناسه تراکنش نامعتبر'); }

        $resp = http_post('https://nextpay.org/nx/gateway/verify', [
            'api_key'  => $opts['nextpayKey'] ?? '',
            'trans_id' => $transId,
            'amount'   => $rials,
        ]);
        $data = json_decode($resp, true);

        if (($data['code'] ?? -1) == 0) {
            $verified = true;
            esi_execute($db, "UPDATE `esi_transactions` SET `gateway_ref` = ? WHERE `id` = ?",
                'si', 'NP-' . $transId, $tx['id']);
        }
        break;

    // ── NowPayments ─────────────────────────────────────────────
    case 'nowpay':
        $status = $_GET['status'] ?? '';
        if ($status === 'success') {
            // Verify via API
            $invoiceId = $tx['gateway_ref'] ?? '';
            if (!empty($invoiceId)) {
                $resp = http_get('https://api.nowpayments.io/v1/payment/?invoiceId=' . $invoiceId, [
                    'x-api-key: ' . ($opts['nowpayKey'] ?? ''),
                ]);
                $data = json_decode($resp, true);
                $payments = $data['data'] ?? [];

                foreach ($payments as $pay) {
                    if (in_array($pay['payment_status'] ?? '', ['finished', 'confirmed', 'sending'])) {
                        $verified = true;
                        break;
                    }
                }
            }
        }

        // Also handle IPN callback (POST)
        $rawBody = file_get_contents('php://input');
        if (!empty($rawBody)) {
            $ipnData = json_decode($rawBody, true);
            if (in_array($ipnData['payment_status'] ?? '', ['finished', 'confirmed'])) {
                // Find transaction by order_id
                $orderId = $ipnData['order_id'] ?? '';
                if (!empty($orderId)) {
                    $ipnTx = esi_fetch_one($db, "SELECT * FROM `esi_transactions` WHERE `ref_code` = ?", 's', $orderId);
                    if ($ipnTx && $ipnTx['status'] !== 'approved') {
                        $tx = $ipnTx;
                        $verified = true;
                    }
                }
            }
        }
        break;

    // ── WeSwap ──────────────────────────────────────────────────
    case 'weswap':
        $exchangeId = $tx['gateway_ref'] ?? '';
        if (!empty($exchangeId)) {
            $resp = http_get("https://changeto.technology/api/exchange/status/{$exchangeId}?api_key=" . ($opts['weswapKey'] ?? ''));
            $data = json_decode($resp, true);

            if (($data['status'] ?? '') === 'confirmed' || ($data['status'] ?? '') === 'completed') {
                $verified = true;
            }
        }
        break;

    default:
        show_result(false, 'درگاه نامعتبر');
}

if ($verified) {
    // Mark as approved
    esi_execute($db, "UPDATE `esi_transactions` SET `status` = 'approved' WHERE `id` = ?", 'i', $tx['id']);

    // Process the payment
    $txType  = $tx['tx_type'] ?? 'BUY_SUB';
    $memberId = (int)$tx['member_id'];

    if ($txType === 'INCREASE_WALLET') {
        esi_execute($db, "UPDATE `esi_members` SET `balance` = `balance` + ? WHERE `tg_id` = ?", 'ii', $amount, $memberId);
        tg_send("✅ پرداخت موفق!\n💰 " . format_price($amount) . " تومان به کیف پول شما اضافه شد.", null, null, $memberId);
    } else {
        // Notify for subscription/renewal processing
        tg_send("✅ پرداخت شما با موفقیت انجام شد! سرویس شما در حال آماده‌سازی است...", null, null, $memberId);

        // Notify admin
        $memberInfo = esi_get_member($db, $memberId);
        tg_send("💳 پرداخت آنلاین تایید شد\n👤 " . ($memberInfo['display_name'] ?? $memberId) .
            "\n💰 " . format_price($amount) . " تومان\n🔗 درگاه: {$gateway}\n🆔 #{$tx['id']}", null, null, ESI_ADMIN_ID);
    }

    show_result(true, 'پرداخت با موفقیت انجام شد');
} else {
    esi_execute($db, "UPDATE `esi_transactions` SET `status` = 'failed' WHERE `id` = ?", 'i', $tx['id']);
    tg_send("❌ پرداخت ناموفق بود. مبلغ پرداختی (در صورت کسر) ظرف ۷۲ ساعت برگشت داده می‌شود.", null, null, (int)$tx['member_id']);
    show_result(false, 'پرداخت ناموفق بود');
}

$db->close();

function show_result(bool $success, string $message): void {
    $icon  = $success ? '✅' : '❌';
    $color = $success ? '#00b894' : '#d63031';
    $bg    = $success ? '#0a3d2e' : '#3d0a0a';

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$message} - MahsaBot</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Tahoma,'Segoe UI',sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#0f0f23;color:#e0e0e0}
.card{background:#1a1a2e;border-radius:20px;padding:48px 40px;text-align:center;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.05)}
.icon{font-size:64px;margin-bottom:20px}
h2{font-size:20px;margin-bottom:12px;color:{$color}}
p{color:#8888a0;line-height:1.8;font-size:14px;margin-bottom:24px}
.badge{display:inline-block;background:{$bg};color:{$color};padding:8px 20px;border-radius:30px;font-size:13px;border:1px solid {$color}30}
</style>
</head>
<body>
<div class="card">
<div class="icon">{$icon}</div>
<h2>{$message}</h2>
<p>می‌توانید این صفحه را ببندید و به ربات تلگرام بازگردید.</p>
<span class="badge">MahsaBot Payment</span>
</div>
</body>
</html>
HTML;
    exit;
}

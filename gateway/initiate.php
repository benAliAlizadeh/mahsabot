<?php
/**
 * MahsaBot - Payment Gateway Initiation
 * Redirects user to external payment gateway
 * URL: /gateway/initiate.php?token=XXX&gateway=zarinpal
 * 
 * @package MahsaBot
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';

$db = new mysqli(ESI_DB_HOST, ESI_DB_USER, ESI_DB_PASS, ESI_DB_NAME);
$db->set_charset('utf8mb4');
if ($db->connect_error) { show_error('خطا در اتصال به سرور'); }

$GLOBALS['db'] = $db;

$token   = trim($_GET['token'] ?? '');
$gateway = trim($_GET['gateway'] ?? '');

if (empty($token) || empty($gateway)) { show_error('پارامترهای نامعتبر'); }

// Look up transaction
$tx = esi_fetch_one($db, "SELECT * FROM `esi_transactions` WHERE `ref_code` = ?", 's', $token);
if (!$tx || $tx['status'] !== 'pending') { show_error('تراکنش یافت نشد یا قبلاً پردازش شده'); }

$amount = (int)$tx['amount']; // Toman
$rials  = $amount * 10;       // Rials for gateways
$desc   = 'پرداخت MahsaBot - #' . $tx['id'];

// Load payment keys from options
$optRows = esi_fetch_all($db, "SELECT `option_key`, `option_value` FROM `esi_options`");
$opts = [];
foreach ($optRows as $r) { $opts[$r['option_key']] = $r['option_value']; }

$callbackUrl = ESI_BOT_URL . 'gateway/callback.php?gateway=' . $gateway . '&ref=' . $token;

switch ($gateway) {
    case 'zarinpal':
        $merchantId = $opts['zarinpalKey'] ?? '';
        if (empty($merchantId)) { show_error('درگاه زرین‌پال پیکربندی نشده'); }

        try {
            $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', [
                'encoding'           => 'UTF-8',
                'cache_wsdl'         => WSDL_CACHE_NONE,
                'connection_timeout' => 10,
            ]);
            $result = $client->PaymentRequest([
                'MerchantID'  => $merchantId,
                'Amount'      => $rials,
                'Description' => $desc,
                'Email'       => '',
                'Mobile'      => '',
                'CallbackURL' => $callbackUrl,
            ]);

            if ($result->Status == 100) {
                $authority = $result->Authority;
                esi_execute($db, "UPDATE `esi_transactions` SET `gateway_ref` = ? WHERE `id` = ?", 'si', $authority, $tx['id']);
                header("Location: https://www.zarinpal.com/pg/StartPay/{$authority}");
                exit;
            } else {
                show_error('خطای زرین‌پال: کد ' . $result->Status);
            }
        } catch (Exception $e) {
            error_log('MahsaBot Zarinpal error: ' . $e->getMessage());
            show_error('خطا در اتصال به زرین‌پال');
        }
        break;

    case 'nextpay':
        $apiKey = $opts['nextpayKey'] ?? '';
        if (empty($apiKey)) { show_error('درگاه NextPay پیکربندی نشده'); }

        $postData = [
            'api_key'      => $apiKey,
            'amount'       => $rials,
            'order_id'     => $token,
            'callback_uri' => $callbackUrl,
        ];

        $resp = http_post('https://nextpay.org/nx/gateway/token', $postData);
        $data = json_decode($resp, true);

        if (($data['code'] ?? -1) == -1 && !empty($data['trans_id'])) {
            $transId = $data['trans_id'];
            esi_execute($db, "UPDATE `esi_transactions` SET `gateway_ref` = ? WHERE `id` = ?", 'si', $transId, $tx['id']);
            header("Location: https://nextpay.org/nx/gateway/payment/{$transId}");
            exit;
        } else {
            show_error('خطای NextPay: ' . ($data['code'] ?? 'unknown'));
        }
        break;

    case 'nowpay':
        $apiKey = $opts['nowpayKey'] ?? '';
        if (empty($apiKey)) { show_error('درگاه NowPayments پیکربندی نشده'); }

        $postData = json_encode([
            'price_amount'      => $amount / 10, // approximate USD (basic conversion)
            'price_currency'    => 'usd',
            'order_id'          => $token,
            'order_description' => $desc,
            'ipn_callback_url'  => $callbackUrl,
            'success_url'       => ESI_BOT_URL . 'gateway/callback.php?gateway=nowpay&ref=' . $token . '&status=success',
            'cancel_url'        => ESI_BOT_URL . 'gateway/callback.php?gateway=nowpay&ref=' . $token . '&status=cancel',
        ]);

        $resp = http_post('https://api.nowpayments.io/v1/invoice', $postData, [
            'x-api-key: ' . $apiKey,
            'Content-Type: application/json',
        ]);
        $data = json_decode($resp, true);

        if (!empty($data['invoice_url'])) {
            esi_execute($db, "UPDATE `esi_transactions` SET `gateway_ref` = ? WHERE `id` = ?", 'si', ($data['id'] ?? ''), $tx['id']);
            header("Location: " . $data['invoice_url']);
            exit;
        } else {
            show_error('خطای NowPayments: ' . ($data['message'] ?? 'unknown'));
        }
        break;

    case 'weswap':
        $apiKey = $opts['weswapKey'] ?? '';
        if (empty($apiKey)) { show_error('درگاه WeSwap پیکربندی نشده'); }

        $postData = json_encode([
            'api_key'      => $apiKey,
            'amount'       => $amount,
            'callback_url' => $callbackUrl,
            'order_id'     => $token,
        ]);

        $resp = http_post('https://changeto.technology/api/exchange/create', $postData, [
            'Content-Type: application/json',
        ]);
        $data = json_decode($resp, true);

        if (!empty($data['payment_url'])) {
            esi_execute($db, "UPDATE `esi_transactions` SET `gateway_ref` = ? WHERE `id` = ?", 'si', ($data['exchange_id'] ?? ''), $tx['id']);
            header("Location: " . $data['payment_url']);
            exit;
        } else {
            show_error('خطای WeSwap: ' . ($data['message'] ?? 'unknown'));
        }
        break;

    default:
        show_error('درگاه نامعتبر');
}

$db->close();

function show_error(string $msg): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html dir="rtl"><head><meta charset="utf-8"><title>خطا - MahsaBot</title>'
        . '<style>body{font-family:Tahoma,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#1a1a2e;color:#fff;margin:0}'
        . '.card{background:#16213e;border-radius:16px;padding:40px;text-align:center;max-width:400px}'
        . '.icon{font-size:48px;margin-bottom:16px}p{color:#a0a0b0;line-height:1.8}'
        . 'a{color:#4ecdc4;text-decoration:none}</style></head><body>'
        . '<div class="card"><div class="icon">❌</div><h2>' . htmlspecialchars($msg) . '</h2>'
        . '<p>لطفاً به ربات بازگردید و دوباره تلاش کنید.</p></div></body></html>';
    exit;
}

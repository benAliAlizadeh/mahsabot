<?php
/**
 * MahsaBot - Config Lookup Page
 * Web-based config search for users
 * URL: /web/lookup.php?q=configname
 * 
 * @package MahsaBot
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../lib/jdf.php';

$db = new mysqli(ESI_DB_HOST, ESI_DB_USER, ESI_DB_PASS, ESI_DB_NAME);
$db->set_charset('utf8mb4');

$query  = trim($_GET['q'] ?? '');
$result = null;
$error  = '';

if (!empty($query)) {
    if (strlen($query) < 3) {
        $error = 'حداقل ۳ حرف وارد کنید';
    } else {
        $sub = esi_fetch_one($db, "SELECT * FROM `esi_subscriptions` WHERE `config_name` = ?", 's', $query);
        if ($sub) {
            $node = esi_fetch_one($db, "SELECT * FROM `esi_node_info` WHERE `id` = ?", 'i', $sub['node_id']);
            $result = [
                'name'      => $sub['config_name'],
                'status'    => $sub['status'] == 1 ? 'فعال' : 'غیرفعال',
                'statusClass' => $sub['status'] == 1 ? 'active' : 'inactive',
                'location'  => ($node['flag'] ?? '🌐') . ' ' . ($node['title'] ?? '-'),
                'expires'   => $sub['expires_at'] > 0 
                    ? (function_exists('jdate') ? jdate('Y/m/d', $sub['expires_at']) : date('Y/m/d', $sub['expires_at']))
                    : 'نامحدود',
                'created'   => function_exists('jdate') ? jdate('Y/m/d', $sub['created_at']) : date('Y/m/d', $sub['created_at']),
                'subLink'   => ESI_BOT_URL . 'services/subscription.php?token=' . $sub['token'],
            ];
        } else {
            $error = 'سرویسی با این نام یافت نشد';
        }
    }
}

$db->close();
?>
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>جستجوی سرویس - MahsaBot</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: #0a0a1a;
            color: #e0e0f0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .card {
            background: #12122a;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 480px;
            border: 1px solid rgba(108, 92, 231, 0.15);
        }
        h1 {
            text-align: center;
            font-size: 1.5em;
            margin-bottom: 24px;
            background: linear-gradient(135deg, #a29bfe, #00cec9);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .search-form {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
        }
        input[type="text"] {
            flex: 1;
            padding: 14px 18px;
            background: #1a1a3e;
            border: 1px solid rgba(108, 92, 231, 0.3);
            border-radius: 12px;
            color: #e0e0f0;
            font-size: 1em;
            font-family: monospace;
            outline: none;
            transition: border-color 0.3s;
        }
        input:focus { border-color: #6c5ce7; }
        button {
            padding: 14px 24px;
            background: linear-gradient(135deg, #6c5ce7, #5f3dc4);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1em;
            cursor: pointer;
            transition: transform 0.2s;
        }
        button:hover { transform: scale(1.05); }
        .result {
            background: #1a1a3e;
            border-radius: 12px;
            padding: 20px;
        }
        .result-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .result-row:last-child { border: none; }
        .label { color: #7878a0; font-size: 0.9em; }
        .value { font-weight: bold; }
        .active { color: #00cec9; }
        .inactive { color: #ff6b6b; }
        .error { text-align: center; color: #ff6b6b; padding: 16px; }
        .sub-link {
            display: block;
            margin-top: 16px;
            padding: 12px;
            background: #0a0a1a;
            border-radius: 8px;
            color: #a29bfe;
            font-family: monospace;
            font-size: 0.8em;
            word-break: break-all;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>🔍 جستجوی سرویس</h1>
        
        <form class="search-form" method="get">
            <input type="text" name="q" placeholder="نام کانفیگ را وارد کنید..." 
                   value="<?= htmlspecialchars($query) ?>" autocomplete="off">
            <button type="submit">جستجو</button>
        </form>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php elseif ($result): ?>
            <div class="result">
                <div class="result-row">
                    <span class="label">نام سرویس</span>
                    <span class="value"><?= htmlspecialchars($result['name']) ?></span>
                </div>
                <div class="result-row">
                    <span class="label">وضعیت</span>
                    <span class="value <?= $result['statusClass'] ?>"><?= $result['status'] ?></span>
                </div>
                <div class="result-row">
                    <span class="label">لوکیشن</span>
                    <span class="value"><?= htmlspecialchars($result['location']) ?></span>
                </div>
                <div class="result-row">
                    <span class="label">تاریخ ایجاد</span>
                    <span class="value"><?= $result['created'] ?></span>
                </div>
                <div class="result-row">
                    <span class="label">تاریخ انقضا</span>
                    <span class="value"><?= $result['expires'] ?></span>
                </div>
                <a href="<?= htmlspecialchars($result['subLink']) ?>" class="sub-link" target="_blank">
                    📎 لینک اشتراک: <?= htmlspecialchars($result['subLink']) ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

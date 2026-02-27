<?php
/**
 * MahsaBot - Config Search Handler
 * Admin searches configs by name across all active panels.
 *
 * @package MahsaBot\Handlers
 */

if (!$isAdmin) return;

// ── Search Config Entry ─────────────────────────────────────────
if ($data === 'searchUserConfig' || $data === 'searchConfig') {
    tg_delete();
    tg_send('🔍 نام کانفیگ (یا بخشی از آن) را وارد کنید:', $cancelKeyboard);
    esi_set_step($db, $fromId, 'searchConfigName');
}

// ── Process Search ──────────────────────────────────────────────
if ($step === 'searchConfigName' && $text !== $btn['cancel']) {
    $query = trim($text);
    if (mb_strlen($query) < 2) {
        tg_send('❌ حداقل ۲ کاراکتر وارد کنید.');
    } else {
        tg_send('⏳ در حال جستجو...', $removeKeyboard);
        esi_set_step($db, $fromId, 'idle');

        // Get all active nodes
        $nodes = esi_fetch_all($db,
            "SELECT ni.*, nc.panel_url, nc.username, nc.password, nc.panel_type
             FROM `esi_node_info` ni
             JOIN `esi_node_config` nc ON ni.`id` = nc.`id`
             WHERE ni.`active` = 1"
        );

        $results = [];

        foreach ($nodes as $node) {
            $panelType = $node['panel_type'] ?? 'sanaei';

            if ($panelType === 'marzban') {
                // Marzban: search users via API
                $token = marzban_get_token($node);
                if ($token === '') continue;

                $users = marzban_get_users($node, $token);
                foreach ($users as $u) {
                    $uname = $u['username'] ?? '';
                    if (stripos($uname, $query) !== false) {
                        $usedTraffic = format_bytes((float) ($u['used_traffic'] ?? 0));
                        $dataLimit = ($u['data_limit'] ?? 0) > 0
                            ? format_bytes((float) $u['data_limit'])
                            : '♾';
                        $statusLabel = ($u['status'] ?? 'unknown');
                        $expiry = !empty($u['expire']) ? jdate('Y-m-d', (int) $u['expire']) : '♾';

                        $results[] = [
                            'node'   => "{$node['flag']} {$node['title']}",
                            'name'   => $uname,
                            'status' => $statusLabel,
                            'traffic' => "{$usedTraffic} / {$dataLimit}",
                            'expiry' => $expiry,
                        ];
                    }
                }
            } else {
                // X-UI: search in inbound clients
                $inboundResult = xui_get_inbounds($db, $node);
                if (!$inboundResult['success']) continue;

                foreach ($inboundResult['inbounds'] as $ib) {
                    $settings = json_decode($ib['settings'] ?? '{}', true);
                    $clients = $settings['clients'] ?? [];
                    foreach ($clients as $client) {
                        $email = $client['email'] ?? '';
                        if (stripos($email, $query) !== false) {
                            $up = (float) ($ib['up'] ?? 0);
                            $down = (float) ($ib['down'] ?? 0);
                            $total = (float) ($ib['total'] ?? 0);
                            $used = $up + $down;
                            $totalLabel = $total > 0 ? format_traffic($total) : '♾';

                            $results[] = [
                                'node'   => "{$node['flag']} {$node['title']}",
                                'name'   => $email,
                                'status' => ($ib['enable'] ?? false) ? 'active' : 'disabled',
                                'traffic' => format_traffic($used) . " / {$totalLabel}",
                                'expiry' => ($ib['expiryTime'] ?? 0) > 0
                                    ? jdate('Y-m-d', (int) ($ib['expiryTime'] / 1000))
                                    : '♾',
                            ];
                        }
                    }
                }
            }
        }

        if (empty($results)) {
            tg_send("🔍 نتیجه‌ای برای `{$query}` یافت نشد.");
        } else {
            $lines = ["🔍 *نتایج جستجو: {$query}*\n"];
            $count = 0;
            foreach ($results as $r) {
                $count++;
                if ($count > 20) {
                    $lines[] = "\n⚠️ و " . (count($results) - 20) . " نتیجه دیگر...";
                    break;
                }
                $lines[] = "━━━━━━━━━━━━━━━━━━";
                $lines[] = "🖥 سرور: {$r['node']}";
                $lines[] = "👤 نام: `{$r['name']}`";
                $lines[] = "📊 وضعیت: {$r['status']}";
                $lines[] = "📡 ترافیک: {$r['traffic']}";
                $lines[] = "📅 انقضا: {$r['expiry']}";
            }

            $resultText = implode("\n", $lines);
            // Split long messages
            if (mb_strlen($resultText) > 4000) {
                $chunks = str_split($resultText, 4000);
                foreach ($chunks as $chunk) {
                    tg_send($chunk);
                }
            } else {
                tg_send($resultText);
            }
        }
    }
}

// ── Cancel ──────────────────────────────────────────────────────
if ($step === 'searchConfigName' && $text === $btn['cancel']) {
    esi_set_step($db, $fromId, 'idle');
    tg_send($msg['operation_cancelled'] ?? '❌ عملیات لغو شد.', $removeKeyboard);
}

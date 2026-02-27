<?php
/**
 * MahsaBot - Subscription Link Endpoint
 * Returns V2Ray subscription config for clients
 * URL: https://domain/services/subscription.php?token=XXX
 * 
 * @package MahsaBot
 */

// Minimal includes - no Telegram functions needed
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../panels/xui.php';
require_once __DIR__ . '/../panels/marzban.php';

$db = new mysqli(ESI_DB_HOST, ESI_DB_USER, ESI_DB_PASS, ESI_DB_NAME);
$db->set_charset('utf8mb4');
if ($db->connect_error) {
    http_response_code(500);
    exit('Service unavailable');
}

$GLOBALS['db'] = $db;

$token = trim($_GET['token'] ?? '');
if (empty($token)) {
    http_response_code(400);
    exit('Missing token');
}

// Look up subscription
$sub = esi_fetch_one($db, "SELECT * FROM `esi_subscriptions` WHERE `token` = ?", 's', $token);
if (!$sub) {
    http_response_code(404);
    exit('Subscription not found');
}

$nodeConfig = esi_fetch_one($db, "SELECT * FROM `esi_node_config` WHERE `id` = ?", 'i', $sub['node_id']);
if (!$nodeConfig) {
    http_response_code(500);
    exit('Node configuration error');
}

$panelType = $nodeConfig['panel_type'] ?? 'sanaei';

// ── Marzban: Redirect to native subscription ────────────────────
if ($panelType === 'marzban') {
    $userInfo = marzban_get_user_info($db, $nodeConfig, $sub['config_name']);
    if ($userInfo && !empty($userInfo['sub_link'])) {
        $subUrl = rtrim($nodeConfig['panel_url'], '/') . $userInfo['sub_link'];
        header("Location: {$subUrl}");
        exit;
    }

    // Fallback: return stored links
    $links = $userInfo['links'] ?? [];
    if (!empty($links)) {
        output_subscription($sub, implode("\n", $links));
    }

    http_response_code(502);
    exit('Could not fetch subscription from panel');
}

// ── X-UI Panels: Build subscription response ────────────────────
// Refresh connection link from panel
$freshLink = xui_get_connection_link($db, $nodeConfig, $sub);

if ($freshLink) {
    // Update stored link
    esi_execute($db, "UPDATE `esi_subscriptions` SET `connect_link` = ? WHERE `id` = ?", 'si', $freshLink, $sub['id']);
    $link = $freshLink;
} else {
    // Fall back to stored link
    $link = $sub['connect_link'] ?? '';
}

if (empty($link)) {
    http_response_code(404);
    exit('No configuration available');
}

// Get traffic stats for subscription-userinfo header
$trafficInfo = get_subscription_traffic($db, $nodeConfig, $sub);

output_subscription($sub, $link, $trafficInfo);

$db->close();

// ══════════════════════════════════════════════════════════════════

function output_subscription(array $sub, string $links, array $traffic = []): void {
    $configName = $sub['config_name'] ?? 'mahsabot';

    // Standard subscription headers
    header('Content-Type: text/plain; charset=utf-8');
    header("Content-Disposition: inline; filename=\"{$configName}\"");
    header('profile-update-interval: 1');
    header('profile-title: ' . base64_encode($configName));

    // Traffic info header (used by v2ray clients)
    if (!empty($traffic)) {
        $upload   = $traffic['upload'] ?? 0;
        $download = $traffic['download'] ?? 0;
        $total    = $traffic['total'] ?? 0;
        $expire   = $sub['expires_at'] ?? 0;
        header("subscription-userinfo: upload={$upload}; download={$download}; total={$total}; expire={$expire}");
    }

    // Output base64 encoded links
    echo base64_encode($links);
    exit;
}

function get_subscription_traffic(mysqli $db, array $nodeConfig, array $sub): array {
    $panelType = $nodeConfig['panel_type'] ?? 'sanaei';
    $inboundId = (int)($sub['inbound_id'] ?? 0);

    try {
        $inbounds = xui_get_inbounds($db, $nodeConfig);
        if (!$inbounds) return [];

        foreach ($inbounds as $inbound) {
            if ($inboundId === 0) {
                // Whole inbound mode - match by UUID in settings
                $settings = json_decode($inbound['settings'] ?? '{}', true);
                $clients  = $settings['clients'] ?? [];
                foreach ($clients as $client) {
                    $clientId = $client['id'] ?? $client['password'] ?? '';
                    if ($clientId === $sub['config_uuid']) {
                        return [
                            'upload'   => (int)($inbound['up'] ?? 0),
                            'download' => (int)($inbound['down'] ?? 0),
                            'total'    => (int)($inbound['total'] ?? 0),
                        ];
                    }
                }
            } else {
                // Shared inbound mode - match by inbound ID + client stats
                if ((int)$inbound['id'] === $inboundId) {
                    $clientStats = $inbound['clientStats'] ?? [];
                    foreach ($clientStats as $cs) {
                        if (($cs['email'] ?? '') === $sub['config_name']) {
                            return [
                                'upload'   => (int)($cs['up'] ?? 0),
                                'download' => (int)($cs['down'] ?? 0),
                                'total'    => (int)($cs['total'] ?? 0),
                            ];
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("MahsaBot Subscription: Traffic fetch failed for {$sub['config_name']}: " . $e->getMessage());
    }

    return [];
}

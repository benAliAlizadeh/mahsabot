<?php
/**
 * MahsaBot - X-UI Panel API Module
 * 
 * Handles all X-UI panel variants: sanaei (3x-ui), alireza, normal/vaxilu.
 * Cookie-based auth with per-request unique cookie files to avoid race conditions.
 *
 * @package MahsaBot\Panels
 */

if (!defined('ESI_BOT_TOKEN')) exit('No direct access.');

// ─── Constants ──────────────────────────────────────────────────────────────────

define('XUI_BYTES_PER_GB', 1073741824);
define('XUI_MS_PER_DAY', 86400000);
define('XUI_CURL_TIMEOUT', 15);
define('XUI_CURL_CONNECT_TIMEOUT', 10);

// ─── Helper: API URL Prefix ─────────────────────────────────────────────────────

/**
 * Return the URL prefix based on panel type.
 * sanaei (3x-ui) uses /panel, alireza and normal/vaxilu use /xui.
 */
function xui_api_prefix(string $panelType): string {
    return ($panelType === 'sanaei') ? '/panel' : '/xui';
}

// ─── Helper: Build Cookie String ────────────────────────────────────────────────

/**
 * Extract cookie string from raw header text.
 */
function xui_extract_cookie(string $headerText): string {
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $headerText, $matches);
    $cookies = [];
    foreach ($matches[1] as $item) {
        parse_str($item, $parsed);
        $cookies = array_merge($cookies, $parsed);
    }
    if (empty($cookies)) return '';
    $key = array_keys($cookies)[0];
    return $key . '=' . $cookies[$key];
}

// ─── Helper: Standard cURL Headers ──────────────────────────────────────────────

/**
 * Build standard HTTP headers for X-UI API requests.
 */
function xui_build_headers(string $cookie): array {
    return [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/120.0',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.5',
        'Accept-Encoding: gzip, deflate',
        'X-Requested-With: XMLHttpRequest',
        'Cookie: ' . $cookie,
    ];
}

// ─── Helper: Execute Panel API Request ──────────────────────────────────────────

/**
 * Make an authenticated POST request to the X-UI panel.
 *
 * @param string       $url     Full URL endpoint
 * @param string       $cookie  Cookie string from login
 * @param array|string $data    POST data (array for form data, string for raw)
 * @return array|null  Decoded JSON response or null on failure
 */
function xui_api_post(string $url, string $cookie, $data = []): ?array {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_CONNECTTIMEOUT => XUI_CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => XUI_CURL_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER         => false,
        CURLOPT_HTTPHEADER     => xui_build_headers($cookie),
    ];

    if (is_array($data)) {
        $opts[CURLOPT_POSTFIELDS] = $data;
    } else {
        $opts[CURLOPT_POSTFIELDS] = $data;
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log('MahsaBot XUI API Error: ' . curl_error($ch) . ' URL: ' . $url);
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        error_log('MahsaBot XUI API: Invalid JSON response from ' . $url);
        return null;
    }

    return $decoded;
}

// ═════════════════════════════════════════════════════════════════════════════════
// 1. LOGIN
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Authenticate with X-UI panel and obtain session cookie.
 * Uses per-request cookie isolation to prevent race conditions.
 *
 * @param array $nodeConfig  Server configuration (panel_url, username, password, panel_type)
 * @return array ['success' => bool, 'cookie' => string]
 */
function xui_login(array $nodeConfig): array {
    $panelUrl = rtrim($nodeConfig['panel_url'], '/');
    $loginUrl = $panelUrl . '/login';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $loginUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => XUI_CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => XUI_CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS     => http_build_query([
            'username' => $nodeConfig['username'],
            'password' => $nodeConfig['password'],
        ]),
        CURLOPT_HEADER         => true,
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log('MahsaBot XUI Login Error: ' . curl_error($ch) . ' URL: ' . $loginUrl);
        curl_close($ch);
        return ['success' => false, 'cookie' => ''];
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $headerSize);
    $body   = substr($response, $headerSize);
    curl_close($ch);

    $loginData = json_decode($body, true);
    if (empty($loginData['success'])) {
        error_log('MahsaBot XUI Login Failed for: ' . $panelUrl);
        return ['success' => false, 'cookie' => ''];
    }

    $cookie = xui_extract_cookie($header);
    if (empty($cookie)) {
        error_log('MahsaBot XUI Login: No cookie received from ' . $panelUrl);
        return ['success' => false, 'cookie' => ''];
    }

    return ['success' => true, 'cookie' => $cookie];
}

// ═════════════════════════════════════════════════════════════════════════════════
// 2. GET INBOUNDS
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Retrieve all inbounds from the X-UI panel.
 *
 * @param mysqli $db         Database connection
 * @param array  $nodeConfig Server configuration
 * @return array ['success' => bool, 'inbounds' => array]
 */
function xui_get_inbounds(mysqli $db, array $nodeConfig): array {
    $login = xui_login($nodeConfig);
    if (!$login['success']) {
        return ['success' => false, 'inbounds' => []];
    }

    $panelUrl = rtrim($nodeConfig['panel_url'], '/');
    $prefix   = xui_api_prefix($nodeConfig['panel_type'] ?? 'normal');
    $url      = $panelUrl . $prefix . '/inbound/list';

    $result = xui_api_post($url, $login['cookie']);

    if (!$result || empty($result['success'])) {
        error_log('MahsaBot XUI: Failed to get inbounds from ' . $panelUrl);
        return ['success' => false, 'inbounds' => []];
    }

    return ['success' => true, 'inbounds' => $result['obj'] ?? []];
}

// ═════════════════════════════════════════════════════════════════════════════════
// 3. ADD USER ACCOUNT
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Create a new account on the X-UI panel.
 *
 * inbound_id=0: creates a dedicated inbound (POST inbound/add)
 * inbound_id>0: adds a client to an existing inbound
 *   - sanaei/alireza: POST inbound/addClient/
 *   - normal: GET existing inbound, append client, POST inbound/update/{id}
 *
 * @param mysqli $db         Database connection
 * @param array  $pkg        Package configuration
 * @param array  $nodeConfig Server configuration
 * @param string $remark     Client remark/email
 * @param string $uuid       UUID or password for the client
 * @param int    $days       Duration in days (0 = unlimited)
 * @param float  $volume     Volume in GB (0 = unlimited)
 * @return array ['success' => bool, 'uuid' => string, 'link' => string]
 */
function xui_add_user_account(mysqli $db, array $pkg, array $nodeConfig, string $remark, string $uuid, int $days, float $volume): array {
    $login = xui_login($nodeConfig);
    if (!$login['success']) {
        return ['success' => false, 'uuid' => '', 'link' => ''];
    }

    $panelUrl  = rtrim($nodeConfig['panel_url'], '/');
    $panelType = $nodeConfig['panel_type'] ?? 'normal';
    $prefix    = xui_api_prefix($panelType);
    $cookie    = $login['cookie'];
    $inboundId = (int) ($pkg['inbound_id'] ?? 0);
    $protocol  = strtolower($pkg['protocol'] ?? 'vless');

    // Convert volume: GB -> bytes (0 = unlimited)
    $volumeBytes = ($volume > 0) ? (int) floor($volume * XUI_BYTES_PER_GB) : 0;

    // Convert days -> millisecond timestamp (0 = unlimited)
    $expiryTimeMs = ($days > 0)
        ? (int) (floor(microtime(true) * 1000) + ($days * XUI_MS_PER_DAY))
        : 0;

    // Build client object based on panel type
    $clientIdField = ($protocol === 'trojan') ? 'password' : 'id';

    if ($panelType === 'sanaei' || $panelType === 'alireza') {
        $client = [
            $clientIdField => $uuid,
            'enable'       => true,
            'email'        => $remark,
            'limitIp'      => 0,
            'totalGB'      => $volumeBytes,
            'expiryTime'   => $expiryTimeMs,
            'subId'        => generate_token(16),
        ];
        if ($protocol !== 'trojan') {
            $client['flow'] = $pkg['flow'] ?? '';
        }
    } else {
        // normal/vaxilu
        $client = [
            $clientIdField => $uuid,
            'flow'         => $pkg['flow'] ?? '',
            'email'        => $remark,
            'limitIp'      => 0,
            'totalGB'      => $volumeBytes,
            'expiryTime'   => $expiryTimeMs,
        ];
    }

    $apiResult = null;

    if ($inboundId === 0) {
        // ── Create a new dedicated inbound ──────────────────────────────────
        $apiResult = xui_create_new_inbound($panelUrl, $prefix, $cookie, $pkg, $client, $remark, $protocol);
    } elseif ($panelType === 'sanaei' || $panelType === 'alireza') {
        // ── Add client to existing inbound (sanaei/alireza) ─────────────────
        $url = $panelUrl . $prefix . '/inbound/addClient/';
        $settings = json_encode(['clients' => [$client]]);
        $apiResult = xui_api_post($url, $cookie, [
            'id'       => $inboundId,
            'settings' => $settings,
        ]);
    } else {
        // ── Normal panel: get inbound, append client, full update ───────────
        $apiResult = xui_normal_add_client($panelUrl, $prefix, $cookie, $inboundId, $client);
    }

    if (!$apiResult || empty($apiResult['success'])) {
        error_log('MahsaBot XUI: Failed to add account for remark=' . $remark);
        return ['success' => false, 'uuid' => $uuid, 'link' => ''];
    }

    // Build the subscription record stub for link generation
    $sub = [
        'node_id'     => $pkg['node_id'] ?? ($nodeConfig['id'] ?? 0),
        'inbound_id'  => $inboundId ?: ($apiResult['obj']['id'] ?? 0),
        'config_name' => $remark,
        'config_uuid' => $uuid,
        'protocol'    => $protocol,
    ];

    // Generate connection link
    $linkResult = xui_get_connection_link($db, $nodeConfig, $sub);
    $link = $linkResult['success'] ? ($linkResult['link'] ?? '') : '';

    return ['success' => true, 'uuid' => $uuid, 'link' => $link];
}

/**
 * Create a new dedicated inbound on the panel.
 */
function xui_create_new_inbound(string $panelUrl, string $prefix, string $cookie, array $pkg, array $client, string $remark, string $protocol): ?array {
    $port     = (int) ($pkg['custom_port'] ?? get_next_port());
    $netType  = $pkg['net_type'] ?? 'tcp';
    $security = $pkg['security'] ?? 'none';
    $flow     = $pkg['flow'] ?? '';

    $settings = json_encode(['clients' => [$client]]);
    $streamSettings = xui_build_stream_settings($pkg, $security, $netType);
    $sniffing = json_encode([
        'enabled'      => true,
        'destOverride' => ['http', 'tls', 'quic'],
    ]);

    $dataArr = [
        'up'             => 0,
        'down'           => 0,
        'total'          => $client['totalGB'] ?? 0,
        'remark'         => $remark,
        'enable'         => 'true',
        'expiryTime'     => $client['expiryTime'] ?? 0,
        'listen'         => '',
        'port'           => $port,
        'protocol'       => $protocol,
        'settings'       => $settings,
        'streamSettings' => $streamSettings,
        'sniffing'       => $sniffing,
    ];

    $url = $panelUrl . $prefix . '/inbound/add';
    return xui_api_post($url, $cookie, $dataArr);
}

/**
 * For normal/vaxilu panel: fetch existing inbound, append a new client, and push a full update.
 */
function xui_normal_add_client(string $panelUrl, string $prefix, string $cookie, int $inboundId, array $newClient): ?array {
    // Fetch current inbound list
    $listUrl = $panelUrl . $prefix . '/inbound/list';
    $listResult = xui_api_post($listUrl, $cookie);

    if (!$listResult || empty($listResult['success'])) return null;

    $inbound = null;
    foreach (($listResult['obj'] ?? []) as $row) {
        if ((int) ($row['id'] ?? 0) === $inboundId) {
            $inbound = $row;
            break;
        }
    }
    if (!$inbound) return null;

    // Append new client
    $settings = json_decode($inbound['settings'], true) ?: [];
    $settings['clients'][] = $newClient;
    $inbound['settings'] = json_encode($settings, JSON_UNESCAPED_SLASHES);

    $dataArr = [
        'up'             => $inbound['up'] ?? 0,
        'down'           => $inbound['down'] ?? 0,
        'total'          => $inbound['total'] ?? 0,
        'remark'         => $inbound['remark'] ?? '',
        'enable'         => 'true',
        'expiryTime'     => $inbound['expiryTime'] ?? 0,
        'listen'         => '',
        'port'           => $inbound['port'] ?? 0,
        'protocol'       => $inbound['protocol'] ?? '',
        'settings'       => $inbound['settings'],
        'streamSettings' => $inbound['streamSettings'] ?? '{}',
        'sniffing'       => $inbound['sniffing'] ?? '{}',
    ];

    $url = $panelUrl . $prefix . '/inbound/update/' . $inboundId;
    return xui_api_post($url, $cookie, $dataArr);
}

/**
 * Build streamSettings JSON string based on package configuration.
 */
function xui_build_stream_settings(array $pkg, string $security, string $netType): string {
    $stream = [
        'network'  => $netType,
        'security' => $security,
    ];

    // Network-specific settings
    switch ($netType) {
        case 'ws':
            $stream['wsSettings'] = [
                'path'    => $pkg['custom_path'] ?? '/',
                'headers' => ['Host' => $pkg['custom_sni'] ?? ''],
            ];
            break;
        case 'grpc':
            $stream['grpcSettings'] = [
                'serviceName' => $pkg['custom_path'] ?? '',
                'multiMode'   => false,
            ];
            break;
        case 'kcp':
            $stream['kcpSettings'] = [
                'mtu'              => 1350,
                'tti'              => 50,
                'uplinkCapacity'   => 5,
                'downlinkCapacity' => 20,
                'congestion'       => false,
                'readBufferSize'   => 2,
                'writeBufferSize'  => 2,
                'header'           => ['type' => 'none'],
                'seed'             => '',
            ];
            break;
        case 'tcp':
        default:
            $stream['tcpSettings'] = [
                'header' => ['type' => 'none'],
            ];
            break;
    }

    // Security settings
    if ($security === 'tls') {
        $stream['tlsSettings'] = [
            'serverName'   => $pkg['custom_sni'] ?? '',
            'certificates' => [['certificateFile' => '', 'keyFile' => '']],
            'alpn'         => ['h2', 'http/1.1'],
        ];
    } elseif ($security === 'reality') {
        $stream['realitySettings'] = [
            'show'        => false,
            'dest'        => $pkg['reality_dest'] ?? '',
            'xver'        => 0,
            'serverNames' => [$pkg['reality_sni'] ?? ''],
            'privateKey'  => '',
            'shortIds'    => [generate_short_id()],
            'settings'    => [
                'publicKey'   => '',
                'fingerprint' => $pkg['reality_fingerprint'] ?? 'chrome',
                'serverName'  => $pkg['reality_sni'] ?? '',
                'spiderX'     => $pkg['reality_spider'] ?? '/',
            ],
        ];
    } elseif ($security === 'xtls') {
        $stream['xtlsSettings'] = [
            'serverName'   => $pkg['custom_sni'] ?? '',
            'certificates' => [['certificateFile' => '', 'keyFile' => '']],
        ];
    }

    return json_encode($stream, JSON_UNESCAPED_SLASHES);
}

// ═════════════════════════════════════════════════════════════════════════════════
// 4. EDIT TRAFFIC (Renew / Add)
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Edit traffic/expiry for an existing account.
 *
 * @param mysqli $db         Database connection
 * @param array  $nodeConfig Server configuration
 * @param array  $sub        Subscription record
 * @param string $editType   'renew' or 'add'
 * @param int    $days       Days to set/add
 * @param float  $volume     Volume in GB to set/add
 * @return array ['success' => bool]
 */
function xui_edit_traffic(mysqli $db, array $nodeConfig, array $sub, string $editType, int $days, float $volume): array {
    $login = xui_login($nodeConfig);
    if (!$login['success']) {
        return ['success' => false];
    }

    $panelUrl  = rtrim($nodeConfig['panel_url'], '/');
    $panelType = $nodeConfig['panel_type'] ?? 'normal';
    $prefix    = xui_api_prefix($panelType);
    $cookie    = $login['cookie'];
    $inboundId = (int) ($sub['inbound_id'] ?? 0);
    $uuid      = $sub['config_uuid'] ?? '';
    $remark    = $sub['config_name'] ?? '';
    $protocol  = strtolower($sub['protocol'] ?? 'vless');

    // Fetch current inbound data
    $listUrl = $panelUrl . $prefix . '/inbound/list';
    $listResult = xui_api_post($listUrl, $cookie);
    if (!$listResult || empty($listResult['success'])) {
        return ['success' => false];
    }

    $inbound  = null;
    $clientKey = -1;
    $clientIdField = ($protocol === 'trojan') ? 'password' : 'id';

    foreach (($listResult['obj'] ?? []) as $row) {
        if ($inboundId === 0) {
            // Dedicated inbound: find by UUID in first client
            $settings = json_decode($row['settings'] ?? '{}', true);
            $clients = $settings['clients'] ?? [];
            if (!empty($clients[0][$clientIdField]) && $clients[0][$clientIdField] === $uuid) {
                $inbound = $row;
                $clientKey = 0;
                break;
            }
        } else {
            if ((int) ($row['id'] ?? 0) === $inboundId) {
                $inbound = $row;
                $settings = json_decode($row['settings'] ?? '{}', true);
                $clients = $settings['clients'] ?? [];
                foreach ($clients as $k => $c) {
                    if (($c[$clientIdField] ?? '') === $uuid || ($c['password'] ?? '') === $uuid || ($c['id'] ?? '') === $uuid) {
                        $clientKey = $k;
                        break;
                    }
                }
                break;
            }
        }
    }

    if (!$inbound || $clientKey === -1) {
        error_log('MahsaBot XUI: Inbound/client not found for uuid=' . $uuid);
        return ['success' => false];
    }

    $settings = json_decode($inbound['settings'], true);
    $nowMs    = (int) floor(microtime(true) * 1000);

    // ── Handle volume ───────────────────────────────────────────────────────
    if ($volume > 0) {
        $extendBytes = (int) floor($volume * XUI_BYTES_PER_GB);

        if ($inboundId === 0) {
            // Dedicated inbound: update inbound-level total
            if ($editType === 'renew') {
                $inbound['up'] = 0;
                $inbound['down'] = 0;
                $inbound['total'] = $extendBytes;
            } else {
                $currentTotal = (int) ($inbound['total'] ?? 0);
                $inbound['total'] = ($currentTotal > 0) ? $currentTotal + $extendBytes : $extendBytes;
            }
        } else {
            // Shared inbound: update client-level totalGB
            $currentGB = (int) ($settings['clients'][$clientKey]['totalGB'] ?? 0);
            if ($editType === 'renew') {
                // Reset traffic counters on panel
                xui_reset_traffic($nodeConfig, $cookie, $remark, $inboundId);
                $settings['clients'][$clientKey]['totalGB'] = $extendBytes;
            } else {
                $settings['clients'][$clientKey]['totalGB'] = ($currentGB > 0) ? $currentGB + $extendBytes : $extendBytes;
            }
        }
    }

    // ── Handle days ─────────────────────────────────────────────────────────
    if ($days > 0) {
        $extendMs = $days * XUI_MS_PER_DAY;

        if ($inboundId === 0) {
            $currentExpiry = (int) ($inbound['expiryTime'] ?? 0);
            if ($editType === 'renew') {
                $inbound['expiryTime'] = $nowMs + $extendMs;
            } else {
                $inbound['expiryTime'] = ($nowMs > $currentExpiry) ? $nowMs + $extendMs : $currentExpiry + $extendMs;
            }
        } else {
            $currentExpiry = (int) ($settings['clients'][$clientKey]['expiryTime'] ?? 0);
            if ($editType === 'renew') {
                $settings['clients'][$clientKey]['expiryTime'] = $nowMs + $extendMs;
            } else {
                $settings['clients'][$clientKey]['expiryTime'] = ($nowMs > $currentExpiry) ? $nowMs + $extendMs : $currentExpiry + $extendMs;
            }
        }
    }

    // Ensure sanaei/alireza fields exist
    if ($panelType === 'sanaei' || $panelType === 'alireza') {
        if (!isset($settings['clients'][$clientKey]['subId'])) {
            $settings['clients'][$clientKey]['subId'] = generate_token(16);
        }
        if (!isset($settings['clients'][$clientKey]['enable'])) {
            $settings['clients'][$clientKey]['enable'] = true;
        }
    }

    // ── Send update ─────────────────────────────────────────────────────────
    if ($inboundId > 0 && ($panelType === 'sanaei' || $panelType === 'alireza')) {
        // Use updateClient endpoint
        $editedClient = $settings['clients'][$clientKey];
        $newSetting = json_encode(['clients' => [$editedClient]]);
        $url = $panelUrl . $prefix . '/inbound/updateClient/' . rawurlencode($uuid);
        $apiResult = xui_api_post($url, $cookie, [
            'id'       => $inboundId,
            'settings' => $newSetting,
        ]);
    } else {
        // Full inbound update (normal panel or dedicated inbound)
        $settings['clients'] = array_values($settings['clients']);
        $inbound['settings'] = json_encode($settings, JSON_UNESCAPED_SLASHES);
        $inboundIdForUrl = ($inboundId > 0) ? $inboundId : (int) ($inbound['id'] ?? 0);

        $dataArr = [
            'up'             => $inbound['up'] ?? 0,
            'down'           => $inbound['down'] ?? 0,
            'total'          => $inbound['total'] ?? 0,
            'remark'         => $inbound['remark'] ?? '',
            'enable'         => 'true',
            'expiryTime'     => $inbound['expiryTime'] ?? 0,
            'listen'         => '',
            'port'           => $inbound['port'] ?? 0,
            'protocol'       => $inbound['protocol'] ?? '',
            'settings'       => $inbound['settings'],
            'streamSettings' => $inbound['streamSettings'] ?? '{}',
            'sniffing'       => $inbound['sniffing'] ?? '{}',
        ];

        $url = $panelUrl . $prefix . '/inbound/update/' . $inboundIdForUrl;
        $apiResult = xui_api_post($url, $cookie, $dataArr);
    }

    if (!$apiResult || empty($apiResult['success'])) {
        error_log('MahsaBot XUI: Failed to edit traffic for uuid=' . $uuid);
        return ['success' => false];
    }

    return ['success' => true];
}

// ═════════════════════════════════════════════════════════════════════════════════
// 5. CHANGE STATE (Enable / Disable)
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Toggle enable/disable state of an account.
 *
 * @param mysqli $db         Database connection
 * @param array  $nodeConfig Server configuration
 * @param array  $sub        Subscription record
 * @param bool   $enable     true = enable, false = disable
 * @return array ['success' => bool]
 */
function xui_change_state(mysqli $db, array $nodeConfig, array $sub, bool $enable): array {
    $login = xui_login($nodeConfig);
    if (!$login['success']) {
        return ['success' => false];
    }

    $panelUrl  = rtrim($nodeConfig['panel_url'], '/');
    $panelType = $nodeConfig['panel_type'] ?? 'normal';
    $prefix    = xui_api_prefix($panelType);
    $cookie    = $login['cookie'];
    $inboundId = (int) ($sub['inbound_id'] ?? 0);
    $uuid      = $sub['config_uuid'] ?? '';
    $protocol  = strtolower($sub['protocol'] ?? 'vless');
    $clientIdField = ($protocol === 'trojan') ? 'password' : 'id';

    // Fetch inbound data
    $listUrl = $panelUrl . $prefix . '/inbound/list';
    $listResult = xui_api_post($listUrl, $cookie);
    if (!$listResult || empty($listResult['success'])) {
        return ['success' => false];
    }

    $inbound  = null;
    $clientKey = -1;

    foreach (($listResult['obj'] ?? []) as $row) {
        if ($inboundId === 0) {
            $settings = json_decode($row['settings'] ?? '{}', true);
            $clients = $settings['clients'] ?? [];
            if (!empty($clients[0][$clientIdField]) && $clients[0][$clientIdField] === $uuid) {
                $inbound = $row;
                $clientKey = 0;
                break;
            }
        } else {
            if ((int) ($row['id'] ?? 0) === $inboundId) {
                $inbound = $row;
                $settings = json_decode($row['settings'] ?? '{}', true);
                $clients = $settings['clients'] ?? [];
                foreach ($clients as $k => $c) {
                    if (($c[$clientIdField] ?? '') === $uuid || ($c['password'] ?? '') === $uuid || ($c['id'] ?? '') === $uuid) {
                        $clientKey = $k;
                        break;
                    }
                }
                break;
            }
        }
    }

    if (!$inbound || $clientKey === -1) {
        error_log('MahsaBot XUI: Client not found for state change, uuid=' . $uuid);
        return ['success' => false];
    }

    $settings = json_decode($inbound['settings'], true);
    $settings['clients'][$clientKey]['enable'] = $enable;

    // Ensure sanaei/alireza fields
    if (($panelType === 'sanaei' || $panelType === 'alireza') && !isset($settings['clients'][$clientKey]['subId'])) {
        $settings['clients'][$clientKey]['subId'] = generate_token(16);
    }

    // ── Send update ─────────────────────────────────────────────────────────
    if ($inboundId > 0 && ($panelType === 'sanaei' || $panelType === 'alireza')) {
        $editedClient = $settings['clients'][$clientKey];
        $newSetting = json_encode(['clients' => [$editedClient]]);
        $url = $panelUrl . $prefix . '/inbound/updateClient/' . rawurlencode($uuid);
        $apiResult = xui_api_post($url, $cookie, [
            'id'       => $inboundId,
            'settings' => $newSetting,
        ]);
    } else {
        // Full inbound update
        $settings['clients'] = array_values($settings['clients']);
        $inbound['settings'] = json_encode($settings, JSON_UNESCAPED_SLASHES);
        $inboundIdForUrl = ($inboundId > 0) ? $inboundId : (int) ($inbound['id'] ?? 0);

        // For dedicated inbound (inbound_id=0), also toggle the inbound enable
        $enableStr = ($inboundId === 0) ? ($enable ? 'true' : 'false') : 'true';

        $dataArr = [
            'up'             => $inbound['up'] ?? 0,
            'down'           => $inbound['down'] ?? 0,
            'total'          => $inbound['total'] ?? 0,
            'remark'         => $inbound['remark'] ?? '',
            'enable'         => $enableStr,
            'expiryTime'     => $inbound['expiryTime'] ?? 0,
            'listen'         => '',
            'port'           => $inbound['port'] ?? 0,
            'protocol'       => $inbound['protocol'] ?? '',
            'settings'       => $inbound['settings'],
            'streamSettings' => $inbound['streamSettings'] ?? '{}',
            'sniffing'       => $inbound['sniffing'] ?? '{}',
        ];

        $url = $panelUrl . $prefix . '/inbound/update/' . $inboundIdForUrl;
        $apiResult = xui_api_post($url, $cookie, $dataArr);
    }

    if (!$apiResult || empty($apiResult['success'])) {
        error_log('MahsaBot XUI: Failed to change state for uuid=' . $uuid);
        return ['success' => false];
    }

    return ['success' => true];
}

// ═════════════════════════════════════════════════════════════════════════════════
// 6. DELETE ACCOUNT
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Delete an account from the X-UI panel.
 *
 * inbound_id=0: delete the whole inbound
 * inbound_id>0:
 *   - sanaei/alireza: POST inbound/{id}/delClient/{uuid}
 *   - normal: remove client from array, full update
 *
 * @param mysqli $db         Database connection
 * @param array  $nodeConfig Server configuration
 * @param array  $sub        Subscription record
 * @return array ['success' => bool]
 */
function xui_delete_account(mysqli $db, array $nodeConfig, array $sub): array {
    $login = xui_login($nodeConfig);
    if (!$login['success']) {
        return ['success' => false];
    }

    $panelUrl  = rtrim($nodeConfig['panel_url'], '/');
    $panelType = $nodeConfig['panel_type'] ?? 'normal';
    $prefix    = xui_api_prefix($panelType);
    $cookie    = $login['cookie'];
    $inboundId = (int) ($sub['inbound_id'] ?? 0);
    $uuid      = $sub['config_uuid'] ?? '';
    $protocol  = strtolower($sub['protocol'] ?? 'vless');

    if ($inboundId === 0) {
        // ── Dedicated inbound: find and delete entire inbound ───────────────
        $listUrl = $panelUrl . $prefix . '/inbound/list';
        $listResult = xui_api_post($listUrl, $cookie);
        if (!$listResult || empty($listResult['success'])) {
            return ['success' => false];
        }

        $clientIdField = ($protocol === 'trojan') ? 'password' : 'id';
        $realInboundId = 0;

        foreach (($listResult['obj'] ?? []) as $row) {
            $settings = json_decode($row['settings'] ?? '{}', true);
            $clients = $settings['clients'] ?? [];
            if (!empty($clients[0][$clientIdField]) && $clients[0][$clientIdField] === $uuid) {
                $realInboundId = (int) ($row['id'] ?? 0);
                break;
            }
        }

        if ($realInboundId === 0) {
            error_log('MahsaBot XUI: Dedicated inbound not found for uuid=' . $uuid);
            return ['success' => false];
        }

        $url = $panelUrl . $prefix . '/inbound/del/' . $realInboundId;
        $apiResult = xui_api_post($url, $cookie);
    } elseif ($panelType === 'sanaei' || $panelType === 'alireza') {
        // ── sanaei/alireza: use delClient endpoint ──────────────────────────
        $url = $panelUrl . $prefix . '/inbound/' . $inboundId . '/delClient/' . rawurlencode($uuid);
        $apiResult = xui_api_post($url, $cookie);
    } else {
        // ── Normal panel: remove client from array, full update ─────────────
        $listUrl = $panelUrl . $prefix . '/inbound/list';
        $listResult = xui_api_post($listUrl, $cookie);
        if (!$listResult || empty($listResult['success'])) {
            return ['success' => false];
        }

        $inbound = null;
        foreach (($listResult['obj'] ?? []) as $row) {
            if ((int) ($row['id'] ?? 0) === $inboundId) {
                $inbound = $row;
                break;
            }
        }
        if (!$inbound) return ['success' => false];

        $settings = json_decode($inbound['settings'], true) ?: [];
        $clients = $settings['clients'] ?? [];
        $clientIdField = ($protocol === 'trojan') ? 'password' : 'id';

        foreach ($clients as $key => $client) {
            if (($client[$clientIdField] ?? '') === $uuid || ($client['password'] ?? '') === $uuid || ($client['id'] ?? '') === $uuid) {
                unset($clients[$key]);
                break;
            }
        }

        $settings['clients'] = array_values($clients);
        $inbound['settings'] = json_encode($settings, JSON_UNESCAPED_SLASHES);

        $dataArr = [
            'up'             => $inbound['up'] ?? 0,
            'down'           => $inbound['down'] ?? 0,
            'total'          => $inbound['total'] ?? 0,
            'remark'         => $inbound['remark'] ?? '',
            'enable'         => 'true',
            'expiryTime'     => $inbound['expiryTime'] ?? 0,
            'listen'         => '',
            'port'           => $inbound['port'] ?? 0,
            'protocol'       => $inbound['protocol'] ?? '',
            'settings'       => $inbound['settings'],
            'streamSettings' => $inbound['streamSettings'] ?? '{}',
            'sniffing'       => $inbound['sniffing'] ?? '{}',
        ];

        $url = $panelUrl . $prefix . '/inbound/update/' . $inboundId;
        $apiResult = xui_api_post($url, $cookie, $dataArr);
    }

    if (!$apiResult || empty($apiResult['success'])) {
        error_log('MahsaBot XUI: Failed to delete account uuid=' . $uuid);
        return ['success' => false];
    }

    return ['success' => true];
}

// ═════════════════════════════════════════════════════════════════════════════════
// 7. RENEW UUID
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Generate a new UUID for an existing account and update it on the panel.
 *
 * @param mysqli $db         Database connection
 * @param array  $nodeConfig Server configuration
 * @param array  $sub        Subscription record
 * @return array ['success' => bool, 'uuid' => string]
 */
function xui_renew_uuid(mysqli $db, array $nodeConfig, array $sub): array {
    $login = xui_login($nodeConfig);
    if (!$login['success']) {
        return ['success' => false, 'uuid' => ''];
    }

    $panelUrl  = rtrim($nodeConfig['panel_url'], '/');
    $panelType = $nodeConfig['panel_type'] ?? 'normal';
    $prefix    = xui_api_prefix($panelType);
    $cookie    = $login['cookie'];
    $inboundId = (int) ($sub['inbound_id'] ?? 0);
    $uuid      = $sub['config_uuid'] ?? '';
    $protocol  = strtolower($sub['protocol'] ?? 'vless');
    $clientIdField = ($protocol === 'trojan') ? 'password' : 'id';

    // Fetch inbound data
    $listUrl = $panelUrl . $prefix . '/inbound/list';
    $listResult = xui_api_post($listUrl, $cookie);
    if (!$listResult || empty($listResult['success'])) {
        return ['success' => false, 'uuid' => ''];
    }

    $inbound  = null;
    $clientKey = -1;

    foreach (($listResult['obj'] ?? []) as $row) {
        if ($inboundId === 0) {
            $settings = json_decode($row['settings'] ?? '{}', true);
            $clients = $settings['clients'] ?? [];
            if (!empty($clients[0][$clientIdField]) && $clients[0][$clientIdField] === $uuid) {
                $inbound = $row;
                $clientKey = 0;
                break;
            }
        } else {
            if ((int) ($row['id'] ?? 0) === $inboundId) {
                $inbound = $row;
                $settings = json_decode($row['settings'] ?? '{}', true);
                $clients = $settings['clients'] ?? [];
                foreach ($clients as $k => $c) {
                    if (($c[$clientIdField] ?? '') === $uuid || ($c['password'] ?? '') === $uuid || ($c['id'] ?? '') === $uuid) {
                        $clientKey = $k;
                        break;
                    }
                }
                break;
            }
        }
    }

    if (!$inbound || $clientKey === -1) {
        error_log('MahsaBot XUI: Client not found for UUID renewal, uuid=' . $uuid);
        return ['success' => false, 'uuid' => ''];
    }

    $settings = json_decode($inbound['settings'], true);
    $newUuid  = generate_uuid();

    // Update the UUID/password
    if ($protocol === 'trojan') {
        $settings['clients'][$clientKey]['password'] = $newUuid;
    } else {
        $settings['clients'][$clientKey]['id'] = $newUuid;
    }

    // Ensure sanaei/alireza fields
    if ($panelType === 'sanaei' || $panelType === 'alireza') {
        if (!isset($settings['clients'][$clientKey]['subId'])) {
            $settings['clients'][$clientKey]['subId'] = generate_token(16);
        }
        if (!isset($settings['clients'][$clientKey]['enable'])) {
            $settings['clients'][$clientKey]['enable'] = true;
        }
    }

    // ── Send update ─────────────────────────────────────────────────────────
    if ($inboundId > 0 && ($panelType === 'sanaei' || $panelType === 'alireza')) {
        $editedClient = $settings['clients'][$clientKey];
        $newSetting = json_encode(['clients' => [$editedClient]]);
        $url = $panelUrl . $prefix . '/inbound/updateClient/' . rawurlencode($uuid);
        $apiResult = xui_api_post($url, $cookie, [
            'id'       => $inboundId,
            'settings' => $newSetting,
        ]);
    } else {
        // Full inbound update
        $settings['clients'] = array_values($settings['clients']);
        $inbound['settings'] = json_encode($settings, JSON_UNESCAPED_SLASHES);
        $inboundIdForUrl = ($inboundId > 0) ? $inboundId : (int) ($inbound['id'] ?? 0);

        $dataArr = [
            'up'             => $inbound['up'] ?? 0,
            'down'           => $inbound['down'] ?? 0,
            'total'          => $inbound['total'] ?? 0,
            'remark'         => $inbound['remark'] ?? '',
            'enable'         => 'true',
            'expiryTime'     => $inbound['expiryTime'] ?? 0,
            'listen'         => '',
            'port'           => $inbound['port'] ?? 0,
            'protocol'       => $inbound['protocol'] ?? '',
            'settings'       => $inbound['settings'],
            'streamSettings' => $inbound['streamSettings'] ?? '{}',
            'sniffing'       => $inbound['sniffing'] ?? '{}',
        ];

        $url = $panelUrl . $prefix . '/inbound/update/' . $inboundIdForUrl;
        $apiResult = xui_api_post($url, $cookie, $dataArr);
    }

    if (!$apiResult || empty($apiResult['success'])) {
        error_log('MahsaBot XUI: Failed to renew UUID for old_uuid=' . $uuid);
        return ['success' => false, 'uuid' => ''];
    }

    return ['success' => true, 'uuid' => $newUuid];
}

// ═════════════════════════════════════════════════════════════════════════════════
// 8. RESET TRAFFIC
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Reset traffic counters for a specific client.
 *
 * URL varies by panel type:
 *   sanaei:  /panel/inbound/{id}/resetClientTraffic/{remark}
 *   alireza: /xui/inbound/{id}/resetClientTraffic/{remark}
 *   normal:  /xui/inbound/resetClientTraffic/{remark}
 *
 * @param array  $nodeConfig Server configuration
 * @param string $cookie     Session cookie
 * @param string $remark     Client email/remark
 * @param int    $inboundId  Inbound ID (used by sanaei/alireza)
 * @return array ['success' => bool]
 */
function xui_reset_traffic(array $nodeConfig, string $cookie, string $remark, int $inboundId = 0): array {
    $panelUrl  = rtrim($nodeConfig['panel_url'], '/');
    $panelType = $nodeConfig['panel_type'] ?? 'normal';
    $encodedRemark = rawurlencode($remark);

    if ($panelType === 'sanaei') {
        $url = $panelUrl . '/panel/inbound/' . $inboundId . '/resetClientTraffic/' . $encodedRemark;
    } elseif ($panelType === 'alireza') {
        $url = $panelUrl . '/xui/inbound/' . $inboundId . '/resetClientTraffic/' . $encodedRemark;
    } else {
        // normal/vaxilu - no inbound ID in URL
        $url = $panelUrl . '/xui/inbound/resetClientTraffic/' . $encodedRemark;
    }

    $apiResult = xui_api_post($url, $cookie);

    if (!$apiResult || empty($apiResult['success'])) {
        error_log('MahsaBot XUI: Failed to reset traffic for remark=' . $remark);
        return ['success' => false];
    }

    return ['success' => true];
}

// ═════════════════════════════════════════════════════════════════════════════════
// 9. GET CONNECTION LINK
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Generate vmess/vless/trojan connection links for a subscription.
 *
 * Handles multiple server IPs (separated by \n), all network types,
 * and all security modes (none, tls, xtls, reality).
 *
 * @param mysqli $db         Database connection
 * @param array  $nodeConfig Server configuration
 * @param array  $sub        Subscription record
 * @return array ['success' => bool, 'link' => string, 'links' => array]
 */
function xui_get_connection_link(mysqli $db, array $nodeConfig, array $sub): array {
    $login = xui_login($nodeConfig);
    if (!$login['success']) {
        return ['success' => false, 'link' => '', 'links' => []];
    }

    $panelUrl  = rtrim($nodeConfig['panel_url'], '/');
    $panelType = $nodeConfig['panel_type'] ?? 'normal';
    $prefix    = xui_api_prefix($panelType);
    $cookie    = $login['cookie'];
    $inboundId = (int) ($sub['inbound_id'] ?? 0);
    $uuid      = $sub['config_uuid'] ?? '';
    $remark    = $sub['config_name'] ?? '';
    $protocol  = strtolower($sub['protocol'] ?? 'vless');
    $clientIdField = ($protocol === 'trojan') ? 'password' : 'id';

    // Determine server IPs
    $serverIps = array_filter(
        array_map('trim', explode("\n", str_replace("\r", '', $nodeConfig['ip'] ?? ''))),
        'strlen'
    );
    if (empty($serverIps)) {
        // Fallback: extract IP/hostname from panel URL
        $parsed = parse_url($panelUrl);
        $serverIps = [$parsed['host'] ?? '127.0.0.1'];
    }

    // Fetch inbound list
    $listUrl = $panelUrl . $prefix . '/inbound/list';
    $listResult = xui_api_post($listUrl, $cookie);
    if (!$listResult || empty($listResult['success'])) {
        return ['success' => false, 'link' => '', 'links' => []];
    }

    // Find matching inbound
    $inbound = null;
    $clientFlow = '';

    foreach (($listResult['obj'] ?? []) as $row) {
        if ($inboundId === 0) {
            $settings = json_decode($row['settings'] ?? '{}', true);
            $clients = $settings['clients'] ?? [];
            if (!empty($clients[0][$clientIdField]) && $clients[0][$clientIdField] === $uuid) {
                $inbound = $row;
                $clientFlow = $clients[0]['flow'] ?? '';
                if (($panelType === 'sanaei' || $panelType === 'alireza') && !empty($clients[0]['email'])) {
                    $remark = $row['remark'] ?? $remark;
                }
                break;
            }
        } else {
            if ((int) ($row['id'] ?? 0) === $inboundId) {
                $inbound = $row;
                $settings = json_decode($row['settings'] ?? '{}', true);
                $clients = $settings['clients'] ?? [];
                foreach ($clients as $c) {
                    if (($c[$clientIdField] ?? '') === $uuid || ($c['password'] ?? '') === $uuid || ($c['id'] ?? '') === $uuid) {
                        $clientFlow = $c['flow'] ?? '';
                        break;
                    }
                }
                break;
            }
        }
    }

    if (!$inbound) {
        error_log('MahsaBot XUI: Inbound not found for link generation, uuid=' . $uuid);
        return ['success' => false, 'link' => '', 'links' => []];
    }

    // Parse stream settings
    $port = (int) ($inbound['port'] ?? 0);
    $streamSettings = json_decode($inbound['streamSettings'] ?? '{}', true);
    $tlsStatus = $streamSettings['security'] ?? 'none';
    $netType   = $streamSettings['network'] ?? 'tcp';

    // Extract network-specific params
    $host = '';
    $path = '';
    $headerType = 'none';
    $serviceName = '';
    $kcpType = 'none';
    $kcpSeed = '';
    $sni = '';
    $alpn = '';
    $flow = $clientFlow;
    $fp = '';
    $pbk = '';
    $sid = '';
    $spiderX = '';

    // ── TCP ──────────────────────────────────────────────────────────────
    if ($netType === 'tcp') {
        $tcpSettings = $streamSettings['tcpSettings'] ?? [];
        $headerType = $tcpSettings['header']['type'] ?? 'none';
        if ($headerType === 'http') {
            $path = $tcpSettings['header']['request']['path'][0] ?? '/';
            $host = $tcpSettings['header']['request']['headers']['Host'][0] ?? '';
        }
    }

    // ── WebSocket ────────────────────────────────────────────────────────
    if ($netType === 'ws') {
        $wsSettings = $streamSettings['wsSettings'] ?? [];
        $path = $wsSettings['path'] ?? '/';
        $host = $wsSettings['headers']['Host'] ?? '';
    }

    // ── gRPC ─────────────────────────────────────────────────────────────
    if ($netType === 'grpc') {
        $grpcSettings = $streamSettings['grpcSettings'] ?? [];
        $serviceName = $grpcSettings['serviceName'] ?? '';
    }

    // ── KCP ──────────────────────────────────────────────────────────────
    if ($netType === 'kcp') {
        $kcpSettings = $streamSettings['kcpSettings'] ?? [];
        $kcpType = $kcpSettings['header']['type'] ?? 'none';
        $kcpSeed = $kcpSettings['seed'] ?? '';
    }

    // ── TLS settings ────────────────────────────────────────────────────
    if ($tlsStatus === 'tls') {
        $tlsSettings = $streamSettings['tlsSettings'] ?? [];
        $sni = $tlsSettings['serverName'] ?? ($tlsSettings['settings']['serverName'] ?? '');
        if (isset($tlsSettings['alpn'])) {
            $alpn = is_array($tlsSettings['alpn']) ? implode(',', $tlsSettings['alpn']) : $tlsSettings['alpn'];
        }
    }

    // ── XTLS settings ───────────────────────────────────────────────────
    if ($tlsStatus === 'xtls') {
        $xtlsSettings = $streamSettings['xtlsSettings'] ?? [];
        $sni = $xtlsSettings['serverName'] ?? ($xtlsSettings['settings']['serverName'] ?? '');
        if (isset($xtlsSettings['alpn'])) {
            $alpn = is_array($xtlsSettings['alpn']) ? implode(',', $xtlsSettings['alpn']) : $xtlsSettings['alpn'];
        }
    }

    // ── Reality settings ────────────────────────────────────────────────
    if ($tlsStatus === 'reality') {
        $realitySettings = $streamSettings['realitySettings'] ?? [];
        $sni     = $realitySettings['serverNames'][0] ?? '';
        $sid     = $realitySettings['shortIds'][0] ?? '';
        $fp      = $realitySettings['settings']['fingerprint'] ?? 'chrome';
        $pbk     = $realitySettings['settings']['publicKey'] ?? '';
        $spiderX = $realitySettings['settings']['spiderX'] ?? '/';
    }

    // ── Build links for each server IP ──────────────────────────────────
    $outputLinks = [];

    foreach ($serverIps as $serverIp) {
        $serverIp = trim($serverIp);
        if (empty($serverIp)) continue;

        $link = '';

        if ($protocol === 'vless') {
            $link = xui_build_vless_link($uuid, $serverIp, $port, $remark, $netType, $tlsStatus, $sni, $host, $path, $headerType, $serviceName, $kcpType, $kcpSeed, $flow, $fp, $pbk, $sid, $spiderX, $alpn);
        } elseif ($protocol === 'trojan') {
            $link = xui_build_trojan_link($uuid, $serverIp, $port, $remark, $netType, $tlsStatus, $sni, $host, $path, $headerType, $serviceName, $kcpType, $kcpSeed, $fp, $pbk, $sid, $spiderX, $alpn);
        } elseif ($protocol === 'vmess') {
            $link = xui_build_vmess_link($uuid, $serverIp, $port, $remark, $netType, $tlsStatus, $sni, $host, $path, $headerType, $serviceName, $kcpType, $kcpSeed, $alpn);
        }

        if (!empty($link)) {
            $outputLinks[] = $link;
        }
    }

    if (empty($outputLinks)) {
        return ['success' => false, 'link' => '', 'links' => []];
    }

    return [
        'success' => true,
        'link'    => $outputLinks[0],
        'links'   => $outputLinks,
    ];
}

// ─── Link Builder: VLESS ────────────────────────────────────────────────────────

function xui_build_vless_link(string $uuid, string $ip, int $port, string $remark, string $net, string $sec, string $sni, string $host, string $path, string $headerType, string $serviceName, string $kcpType, string $kcpSeed, string $flow, string $fp, string $pbk, string $sid, string $spiderX, string $alpn): string {
    $params = "type={$net}&security={$sec}";

    switch ($net) {
        case 'tcp':
            if ($headerType === 'http') {
                $params .= '&headerType=http&path=' . rawurlencode($path ?: '/') . '&host=' . rawurlencode($host);
            }
            break;
        case 'ws':
            $params .= '&path=' . rawurlencode($path ?: '/') . '&host=' . rawurlencode($host);
            break;
        case 'grpc':
            $params .= '&serviceName=' . rawurlencode($serviceName);
            break;
        case 'kcp':
            $params .= '&headerType=' . rawurlencode($kcpType);
            if (!empty($kcpSeed)) $params .= '&seed=' . rawurlencode($kcpSeed);
            break;
    }

    // Security-specific params
    if ($sec === 'tls') {
        if (!empty($sni)) $params .= '&sni=' . rawurlencode($sni);
        if (!empty($alpn)) $params .= '&alpn=' . rawurlencode($alpn);
        if (!empty($fp)) $params .= '&fp=' . rawurlencode($fp);
    } elseif ($sec === 'xtls') {
        if (!empty($sni)) $params .= '&sni=' . rawurlencode($sni);
        if (!empty($flow)) $params .= '&flow=xtls-rprx-direct';
    } elseif ($sec === 'reality') {
        $params .= '&fp=' . rawurlencode($fp ?: 'chrome');
        $params .= '&pbk=' . rawurlencode($pbk);
        $params .= '&sni=' . rawurlencode($sni);
        if (!empty($flow)) $params .= '&flow=' . rawurlencode($flow);
        $params .= '&sid=' . rawurlencode($sid);
        if (!empty($spiderX)) $params .= '&spx=' . rawurlencode($spiderX);
    } else {
        // security=none
        if (!empty($sni)) $params .= '&sni=' . rawurlencode($sni);
    }

    $encodedRemark = rawurlencode($remark);
    return "vless://{$uuid}@{$ip}:{$port}?{$params}#{$encodedRemark}";
}

// ─── Link Builder: Trojan ───────────────────────────────────────────────────────

function xui_build_trojan_link(string $password, string $ip, int $port, string $remark, string $net, string $sec, string $sni, string $host, string $path, string $headerType, string $serviceName, string $kcpType, string $kcpSeed, string $fp, string $pbk, string $sid, string $spiderX, string $alpn): string {
    $params = "type={$net}&security={$sec}";

    switch ($net) {
        case 'tcp':
            if ($headerType === 'http') {
                $params .= '&headerType=http&path=' . rawurlencode($path ?: '/') . '&host=' . rawurlencode($host);
            }
            break;
        case 'ws':
            $params .= '&path=' . rawurlencode($path ?: '/') . '&host=' . rawurlencode($host);
            break;
        case 'grpc':
            $params .= '&serviceName=' . rawurlencode($serviceName);
            break;
        case 'kcp':
            $params .= '&headerType=' . rawurlencode($kcpType);
            if (!empty($kcpSeed)) $params .= '&seed=' . rawurlencode($kcpSeed);
            break;
    }

    if (!empty($sni)) $params .= '&sni=' . rawurlencode($sni);
    if (!empty($alpn)) $params .= '&alpn=' . rawurlencode($alpn);

    if ($sec === 'reality') {
        $params .= '&fp=' . rawurlencode($fp ?: 'chrome');
        $params .= '&pbk=' . rawurlencode($pbk);
        $params .= '&sid=' . rawurlencode($sid);
        if (!empty($spiderX)) $params .= '&spx=' . rawurlencode($spiderX);
    }

    $encodedRemark = rawurlencode($remark);
    return "trojan://{$password}@{$ip}:{$port}?{$params}#{$encodedRemark}";
}

// ─── Link Builder: VMess ────────────────────────────────────────────────────────

function xui_build_vmess_link(string $uuid, string $ip, int $port, string $remark, string $net, string $sec, string $sni, string $host, string $path, string $headerType, string $serviceName, string $kcpType, string $kcpSeed, string $alpn): string {
    $vmess = [
        'v'    => '2',
        'ps'   => $remark,
        'add'  => $ip,
        'port' => $port,
        'id'   => $uuid,
        'aid'  => 0,
        'net'  => $net,
        'type' => 'none',
        'host' => $host ?: '',
        'path' => $path ?: '',
        'tls'  => ($sec === 'none') ? '' : $sec,
    ];

    switch ($net) {
        case 'tcp':
            if ($headerType === 'http') {
                $vmess['type'] = 'http';
                $vmess['host'] = $host;
                $vmess['path'] = $path ?: '/';
            }
            break;
        case 'ws':
            $vmess['host'] = $host;
            $vmess['path'] = $path ?: '/';
            break;
        case 'grpc':
            $vmess['path'] = $serviceName;
            $vmess['type'] = $sec ?: 'gun';
            $vmess['scy']  = 'auto';
            if (!empty($alpn)) $vmess['alpn'] = $alpn;
            break;
        case 'kcp':
            $vmess['type'] = $kcpType ?: 'none';
            if (!empty($kcpSeed)) $vmess['path'] = $kcpSeed;
            break;
    }

    if (!empty($sni)) $vmess['sni'] = $sni;

    $jsonData = json_encode($vmess, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return 'vmess://' . base64_encode($jsonData);
}

// ═════════════════════════════════════════════════════════════════════════════════
// 10. GET NEW X25519 CERT (Reality)
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Request a new X25519 certificate keypair for Reality protocol.
 *
 * @param array  $nodeConfig Server configuration
 * @param string $cookie     Session cookie from login
 * @return array ['success' => bool, 'privateKey' => string, 'publicKey' => string]
 */
function xui_get_new_cert(array $nodeConfig, string $cookie): array {
    $panelUrl = rtrim($nodeConfig['panel_url'], '/');
    $url = $panelUrl . '/server/getNewX25519Cert';

    $apiResult = xui_api_post($url, $cookie);

    if (!$apiResult || empty($apiResult['success'])) {
        error_log('MahsaBot XUI: Failed to get X25519 cert from ' . $panelUrl);
        return ['success' => false, 'privateKey' => '', 'publicKey' => ''];
    }

    $obj = $apiResult['obj'] ?? [];
    return [
        'success'    => true,
        'privateKey' => $obj['privateKey'] ?? '',
        'publicKey'  => $obj['publicKey'] ?? '',
    ];
}

// ═════════════════════════════════════════════════════════════════════════════════
// INTERNAL HELPERS
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Find an inbound row and client index from the panel response.
 * Shared utility to avoid repetition in multiple functions.
 *
 * @param array  $inbounds  List of inbound objects from panel API
 * @param int    $inboundId Inbound ID (0 = dedicated)
 * @param string $uuid      Client UUID/password
 * @param string $protocol  Protocol (vless, vmess, trojan)
 * @return array|null ['inbound' => array, 'client_key' => int] or null
 */
function xui_find_client(array $inbounds, int $inboundId, string $uuid, string $protocol): ?array {
    $clientIdField = ($protocol === 'trojan') ? 'password' : 'id';

    foreach ($inbounds as $row) {
        if ($inboundId === 0) {
            $settings = json_decode($row['settings'] ?? '{}', true);
            $clients = $settings['clients'] ?? [];
            if (!empty($clients[0][$clientIdField]) && $clients[0][$clientIdField] === $uuid) {
                return ['inbound' => $row, 'client_key' => 0];
            }
        } else {
            if ((int) ($row['id'] ?? 0) === $inboundId) {
                $settings = json_decode($row['settings'] ?? '{}', true);
                $clients = $settings['clients'] ?? [];
                foreach ($clients as $k => $c) {
                    if (($c[$clientIdField] ?? '') === $uuid || ($c['password'] ?? '') === $uuid || ($c['id'] ?? '') === $uuid) {
                        return ['inbound' => $row, 'client_key' => $k];
                    }
                }
                return null;
            }
        }
    }

    return null;
}

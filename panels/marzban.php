<?php
/**
 * MahsaBot - Marzban Panel API Module
 *
 * Handles all Marzban panel interactions via JWT-based REST API.
 * Uses Bearer token authentication (not cookies like X-UI).
 *
 * @package MahsaBot\Panels
 */

if (!defined('ESI_BOT_TOKEN')) exit('No direct access.');

// ─── Constants ──────────────────────────────────────────────────────────────────

define('MARZBAN_BYTES_PER_GB', 1073741824);
define('MARZBAN_SECONDS_PER_DAY', 86400);
define('MARZBAN_CURL_TIMEOUT', 15);
define('MARZBAN_CURL_CONNECT_TIMEOUT', 10);

// ═════════════════════════════════════════════════════════════════════════════════
// HELPER: Centralized HTTP Request
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Execute an authenticated HTTP request against the Marzban API.
 *
 * @param string      $url         Full endpoint URL
 * @param string      $method      HTTP method (GET, POST, PUT, DELETE)
 * @param string      $token       Bearer token (empty string for unauthenticated requests)
 * @param mixed       $data        Request body data (array for JSON/form, null for no body)
 * @param string      $contentType 'json' or 'form'
 * @return array|null Decoded JSON response or null on failure
 */
function marzban_request(string $url, string $method = 'GET', string $token = '', $data = null, string $contentType = 'json'): ?array {
    $ch = curl_init();

    $headers = [
        'User-Agent: MahsaBot/1.0',
        'Accept: application/json',
    ];

    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_CONNECTTIMEOUT => MARZBAN_CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => MARZBAN_CURL_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER         => false,
    ];

    if ($data !== null) {
        if ($contentType === 'form') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $opts[CURLOPT_POSTFIELDS] = is_array($data) ? http_build_query($data) : $data;
        } else {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = is_array($data) ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $data;
        }
    }

    $opts[CURLOPT_HTTPHEADER] = $headers;

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log('MahsaBot Marzban API Error: ' . curl_error($ch) . ' URL: ' . $url);
        curl_close($ch);
        return null;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $response === '') {
        error_log('MahsaBot Marzban API: Empty response from ' . $url . ' (HTTP ' . $httpCode . ')');
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        error_log('MahsaBot Marzban API: Invalid JSON response from ' . $url . ' (HTTP ' . $httpCode . ')');
        return null;
    }

    // Marzban returns HTTP 4xx/5xx with error detail
    if ($httpCode >= 400) {
        $detail = $decoded['detail'] ?? 'Unknown error';
        error_log('MahsaBot Marzban API HTTP ' . $httpCode . ': ' . (is_string($detail) ? $detail : json_encode($detail)) . ' URL: ' . $url);
        return null;
    }

    return $decoded;
}

// ═════════════════════════════════════════════════════════════════════════════════
// 1. AUTHENTICATION - Get JWT Token
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Authenticate with Marzban panel and obtain a JWT access token.
 *
 * POST {url}/api/admin/token
 * Content-Type: application/x-www-form-urlencoded
 *
 * @param array $nodeConfig Server configuration (panel_url, username, password)
 * @return string Access token or empty string on failure
 */
function marzban_get_token(array $nodeConfig): string {
    $panelUrl = rtrim($nodeConfig['panel_url'], '/');
    $url      = $panelUrl . '/api/admin/token';

    $result = marzban_request($url, 'POST', '', [
        'username' => $nodeConfig['username'],
        'password' => $nodeConfig['password'],
    ], 'form');

    if (!$result || empty($result['access_token'])) {
        error_log('MahsaBot Marzban: Login failed for ' . $panelUrl);
        return '';
    }

    return $result['access_token'];
}

// ═════════════════════════════════════════════════════════════════════════════════
// 2. ADD USER ACCOUNT
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Create a new user account on the Marzban panel.
 *
 * POST {url}/api/user
 * The $pkg['custom_sni'] field stores JSON containing proxy and inbound configuration:
 *   {"inbounds":{"vmess":["VMESS_INBOUND"]},"proxies":{"vmess":{"id":"uuid"}}}
 *
 * @param mysqli $db         Database connection
 * @param array  $pkg        Package configuration (custom_sni holds proxy/inbound JSON)
 * @param array  $nodeConfig Server configuration
 * @param string $remark     Username / remark for the new user
 * @param int    $days       Duration in days (0 = unlimited)
 * @param float  $volume     Volume in GB (0 = unlimited)
 * @return array ['success'=>bool, 'link'=>string, 'uuid'=>string, 'sub_link'=>string]
 */
function marzban_add_user_account(mysqli $db, array $pkg, array $nodeConfig, string $remark, int $days, float $volume): array {
    $token = marzban_get_token($nodeConfig);
    if ($token === '') {
        return ['success' => false, 'link' => '', 'uuid' => '', 'sub_link' => ''];
    }

    $panelUrl = rtrim($nodeConfig['panel_url'], '/');
    $url      = $panelUrl . '/api/user';

    // Parse proxy/inbound configuration from package
    $customConfig = json_decode($pkg['custom_sni'] ?? '{}', true) ?: [];
    $proxies  = $customConfig['proxies'] ?? [];
    $inbounds = $customConfig['inbounds'] ?? [];

    // Calculate expiry: UNIX seconds (not milliseconds)
    $expire = ($days > 0) ? (time() + ($days * MARZBAN_SECONDS_PER_DAY)) : 0;

    // Calculate data limit: GB -> bytes (0 = unlimited / null)
    $dataLimit = ($volume > 0) ? (int) floor($volume * MARZBAN_BYTES_PER_GB) : 0;

    $body = [
        'username'   => $remark,
        'proxies'    => $proxies,
        'inbounds'   => $inbounds,
        'expire'     => $expire ?: null,
        'data_limit' => $dataLimit ?: null,
    ];

    $result = marzban_request($url, 'POST', $token, $body);

    if (!$result || !isset($result['username'])) {
        error_log('MahsaBot Marzban: Failed to create user ' . $remark);
        return ['success' => false, 'link' => '', 'uuid' => '', 'sub_link' => ''];
    }

    // Extract UUID from the created user proxies
    $uuid = '';
    if (!empty($result['proxies'])) {
        foreach ($result['proxies'] as $proxyType => $proxyData) {
            if (!empty($proxyData['id'])) {
                $uuid = $proxyData['id'];
                break;
            }
            if (!empty($proxyData['password'])) {
                $uuid = $proxyData['password'];
                break;
            }
        }
    }

    // Get subscription link and connection links
    $userInfo = marzban_get_user_info($db, $nodeConfig, $remark);
    $subLink  = $userInfo['sub_link'] ?? ($result['subscription_url'] ?? '');
    $link     = '';
    if (!empty($userInfo['links'])) {
        $link = $userInfo['links'][0];
    } elseif (!empty($result['links'])) {
        $link = $result['links'][0];
    }

    return [
        'success'  => true,
        'link'     => $link,
        'uuid'     => $uuid,
        'sub_link' => $subLink,
    ];
}

// ═════════════════════════════════════════════════════════════════════════════════
// 3. EDIT CONFIG (Renew / Add)
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Edit traffic/expiry for an existing Marzban user.
 *
 * PUT {url}/api/user/{username}
 *
 * @param mysqli $db         Database connection
 * @param array  $nodeConfig Server configuration
 * @param string $remark     Username / remark
 * @param string $editType   'renew' or 'add'
 * @param int    $days       Days to set/add
 * @param float  $volume     Volume in GB to set/add
 * @return bool  True on success
 */
function marzban_edit_config(mysqli $db, array $nodeConfig, string $remark, string $editType, int $days, float $volume): bool {
    $token = marzban_get_token($nodeConfig);
    if ($token === '') {
        return false;
    }

    $panelUrl = rtrim($nodeConfig['panel_url'], '/');

    // Fetch current user data
    $currentUser = marzban_get_user($nodeConfig, $token, $remark);
    if (!$currentUser) {
        error_log('MahsaBot Marzban: User not found for edit: ' . $remark);
        return false;
    }

    $currentExpire    = (int) ($currentUser['expire'] ?? 0);
    $currentDataLimit = (int) ($currentUser['data_limit'] ?? 0);
    $proxies          = $currentUser['proxies'] ?? [];
    $inbounds         = $currentUser['inbounds'] ?? [];

    $newExpire    = $currentExpire;
    $newDataLimit = $currentDataLimit;

    // ── Handle volume ───────────────────────────────────────────────────────
    if ($volume > 0) {
        $extendBytes = (int) floor($volume * MARZBAN_BYTES_PER_GB);

        if ($editType === 'renew') {
            // Reset traffic first, then set new limit
            marzban_reset_traffic($nodeConfig, $token, $remark);
            $newDataLimit = $extendBytes;
        } else {
            // Add to existing limit
            $newDataLimit = ($currentDataLimit > 0) ? $currentDataLimit + $extendBytes : $extendBytes;
        }
    }

    // ── Handle days ─────────────────────────────────────────────────────────
    if ($days > 0) {
        $extendSeconds = $days * MARZBAN_SECONDS_PER_DAY;

        if ($editType === 'renew') {
            $newExpire = time() + $extendSeconds;
        } else {
            // Add from current expiry or now, whichever is later
            $base = max($currentExpire, time());
            $newExpire = $base + $extendSeconds;
        }
    }

    // ── Send update ─────────────────────────────────────────────────────────
    $url  = $panelUrl . '/api/user/' . rawurlencode($remark);
    $body = [
        'username'   => $remark,
        'proxies'    => $proxies,
        'inbounds'   => $inbounds,
        'expire'     => $newExpire ?: null,
        'data_limit' => $newDataLimit ?: null,
        'status'     => 'active',
    ];

    $result = marzban_request($url, 'PUT', $token, $body);

    if (!$result || !isset($result['username'])) {
        error_log('MahsaBot Marzban: Failed to edit config for ' . $remark);
        return false;
    }

    return true;
}

// ═════════════════════════════════════════════════════════════════════════════════
// 4. DELETE USER ACCOUNT
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Delete a user account from the Marzban panel.
 *
 * DELETE {url}/api/user/{username}
 *
 * @param mysqli $db         Database connection
 * @param array  $nodeConfig Server configuration
 * @param string $remark     Username / remark
 * @return bool  True on success
 */
function marzban_delete_user_account(mysqli $db, array $nodeConfig, string $remark): bool {
    $token = marzban_get_token($nodeConfig);
    if ($token === '') {
        return false;
    }

    $panelUrl = rtrim($nodeConfig['panel_url'], '/');
    $url      = $panelUrl . '/api/user/' . rawurlencode($remark);

    $result = marzban_request($url, 'DELETE', $token);

    if ($result === null) {
        error_log('MahsaBot Marzban: Failed to delete user ' . $remark);
        return false;
    }

    return true;
}

// ═════════════════════════════════════════════════════════════════════════════════
// 5. CHANGE STATE (Enable / Disable)
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Toggle enable/disable state of a Marzban user.
 *
 * PUT {url}/api/user/{username}
 * Sets status to "active" or "disabled" while preserving all other fields.
 *
 * @param mysqli $db         Database connection
 * @param array  $nodeConfig Server configuration
 * @param string $remark     Username / remark
 * @param bool   $enable     true = active, false = disabled
 * @return bool  True on success
 */
function marzban_change_state(mysqli $db, array $nodeConfig, string $remark, bool $enable): bool {
    $token = marzban_get_token($nodeConfig);
    if ($token === '') {
        return false;
    }

    $panelUrl = rtrim($nodeConfig['panel_url'], '/');

    // Fetch current user to preserve all fields
    $currentUser = marzban_get_user($nodeConfig, $token, $remark);
    if (!$currentUser) {
        error_log('MahsaBot Marzban: User not found for state change: ' . $remark);
        return false;
    }

    $url  = $panelUrl . '/api/user/' . rawurlencode($remark);
    $body = [
        'username'   => $remark,
        'proxies'    => $currentUser['proxies'] ?? [],
        'inbounds'   => $currentUser['inbounds'] ?? [],
        'expire'     => $currentUser['expire'] ?? null,
        'data_limit' => $currentUser['data_limit'] ?? null,
        'status'     => $enable ? 'active' : 'disabled',
    ];

    $result = marzban_request($url, 'PUT', $token, $body);

    if (!$result || !isset($result['username'])) {
        error_log('MahsaBot Marzban: Failed to change state for ' . $remark);
        return false;
    }

    return true;
}

// ═════════════════════════════════════════════════════════════════════════════════
// 6. RESET TRAFFIC
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Reset traffic counters for a Marzban user.
 *
 * POST {url}/api/user/{username}/reset
 *
 * @param array  $nodeConfig Server configuration
 * @param string $token      Bearer token
 * @param string $remark     Username / remark
 * @return bool  True on success
 */
function marzban_reset_traffic(array $nodeConfig, string $token, string $remark): bool {
    $panelUrl = rtrim($nodeConfig['panel_url'], '/');
    $url      = $panelUrl . '/api/user/' . rawurlencode($remark) . '/reset';

    $result = marzban_request($url, 'POST', $token);

    if ($result === null) {
        error_log('MahsaBot Marzban: Failed to reset traffic for ' . $remark);
        return false;
    }

    return true;
}

// ═════════════════════════════════════════════════════════════════════════════════
// 7. REVOKE SUBSCRIPTION
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Revoke and regenerate subscription URL for a Marzban user.
 *
 * POST {url}/api/user/{username}/revoke_sub
 * After revoking, fetches the updated user info for new subscription link.
 *
 * @param mysqli $db         Database connection
 * @param array  $nodeConfig Server configuration
 * @param string $remark     Username / remark
 * @return string New subscription URL or empty string on failure
 */
function marzban_revoke_subscription(mysqli $db, array $nodeConfig, string $remark): string {
    $token = marzban_get_token($nodeConfig);
    if ($token === '') {
        return '';
    }

    $panelUrl = rtrim($nodeConfig['panel_url'], '/');
    $url      = $panelUrl . '/api/user/' . rawurlencode($remark) . '/revoke_sub';

    $result = marzban_request($url, 'POST', $token);

    if ($result === null) {
        error_log('MahsaBot Marzban: Failed to revoke subscription for ' . $remark);
        return '';
    }

    // Fetch updated user info to get the new subscription link
    $userInfo = marzban_get_user_info($db, $nodeConfig, $remark);

    return $userInfo['sub_link'] ?? '';
}

// ═════════════════════════════════════════════════════════════════════════════════
// 8. GET USER (Raw API)
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Retrieve raw user data from the Marzban API.
 *
 * GET {url}/api/user/{username}
 *
 * Returns user object with: status, data_limit, used_traffic, expire,
 * inbounds, proxies, subscription_url, links, etc.
 *
 * @param array  $nodeConfig Server configuration
 * @param string $token      Bearer token
 * @param string $remark     Username / remark
 * @return array|null User data or null on failure
 */
function marzban_get_user(array $nodeConfig, string $token, string $remark): ?array {
    $panelUrl = rtrim($nodeConfig['panel_url'], '/');
    $url      = $panelUrl . '/api/user/' . rawurlencode($remark);

    $result = marzban_request($url, 'GET', $token);

    if (!$result || !isset($result['username'])) {
        error_log('MahsaBot Marzban: Failed to get user info for ' . $remark);
        return null;
    }

    return $result;
}

// ═════════════════════════════════════════════════════════════════════════════════
// 9. GET USER INFO (with subscription link)
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Get user info including subscription link and connection links.
 *
 * @param mysqli $db         Database connection
 * @param array  $nodeConfig Server configuration
 * @param string $remark     Username / remark
 * @return array ['sub_link'=>string, 'links'=>array]
 */
function marzban_get_user_info(mysqli $db, array $nodeConfig, string $remark): array {
    $token = marzban_get_token($nodeConfig);
    if ($token === '') {
        return ['sub_link' => '', 'links' => []];
    }

    $user = marzban_get_user($nodeConfig, $token, $remark);
    if (!$user) {
        return ['sub_link' => '', 'links' => []];
    }

    $panelUrl = rtrim($nodeConfig['panel_url'], '/');
    $subLink  = '';

    // Build full subscription URL from the relative path
    if (!empty($user['subscription_url'])) {
        $subPath = $user['subscription_url'];
        // If the subscription_url is already absolute, use it as-is
        if (str_starts_with($subPath, 'http://') || str_starts_with($subPath, 'https://')) {
            $subLink = $subPath;
        } else {
            $subLink = $panelUrl . '/' . ltrim($subPath, '/');
        }
    }

    $links = $user['links'] ?? [];

    return [
        'sub_link' => $subLink,
        'links'    => $links,
    ];
}

// ═════════════════════════════════════════════════════════════════════════════════
// 10. GET USER LINK (Convenience)
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Get the primary connection link for a user.
 * Convenience wrapper around marzban_get_user_info().
 *
 * @param mysqli $db         Database connection
 * @param array  $nodeConfig Server configuration
 * @param string $remark     Username / remark
 * @return string Primary connection link or empty string
 */
function marzban_get_user_link(mysqli $db, array $nodeConfig, string $remark): string {
    $userInfo = marzban_get_user_info($db, $nodeConfig, $remark);

    if (!empty($userInfo['links'])) {
        return $userInfo['links'][0];
    }

    return '';
}

// ═════════════════════════════════════════════════════════════════════════════════
// 11. GET ALL USERS
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Retrieve all users from the Marzban panel.
 *
 * GET {url}/api/users
 *
 * @param array  $nodeConfig Server configuration
 * @param string $token      Bearer token
 * @return array Array of user objects or empty array on failure
 */
function marzban_get_users(array $nodeConfig, string $token): array {
    $panelUrl = rtrim($nodeConfig['panel_url'], '/');
    $url      = $panelUrl . '/api/users';

    $result = marzban_request($url, 'GET', $token);

    if (!$result) {
        error_log('MahsaBot Marzban: Failed to get users list');
        return [];
    }

    return $result['users'] ?? $result;
}

// ═════════════════════════════════════════════════════════════════════════════════
// 12. GET HOSTS / CORE CONFIG
// ═════════════════════════════════════════════════════════════════════════════════

/**
 * Retrieve the core (Xray) configuration from Marzban.
 *
 * GET {url}/api/core/config
 *
 * @param array $nodeConfig Server configuration
 * @return array|null Core config or null on failure
 */
function marzban_get_hosts(array $nodeConfig): ?array {
    $token = marzban_get_token($nodeConfig);
    if ($token === '') {
        return null;
    }

    $panelUrl = rtrim($nodeConfig['panel_url'], '/');
    $url      = $panelUrl . '/api/core/config';

    $result = marzban_request($url, 'GET', $token);

    if (!$result) {
        error_log('MahsaBot Marzban: Failed to get core config');
        return null;
    }

    return $result;
}

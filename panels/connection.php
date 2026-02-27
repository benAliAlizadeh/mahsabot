<?php
/**
 * MahsaBot - Connection Link Builder
 * Builds connection links for all panel types
 * Used by payment handler after account creation
 * 
 * @package MahsaBot
 */

if (defined('ESI_CONNECTION_LOADED')) return;
define('ESI_CONNECTION_LOADED', true);

/**
 * Build connection link based on subscription and node configuration
 * Unified function for all panel types
 */
function build_connection_link(array $sub, array $nodeConfig, array $pkg = []): string {
    $protocol = $sub['protocol'] ?? $pkg['protocol'] ?? 'vless';
    $uuid     = $sub['config_uuid'] ?? '';
    $remark   = $sub['config_name'] ?? 'mahsabot';
    
    // Get stream settings from package or node config
    $netType  = $pkg['net_type'] ?? 'ws';
    $security = $pkg['security'] ?? 'tls';
    $flow     = $pkg['flow'] ?? '';
    
    // Server IPs (multi-line)
    $ips = array_filter(array_map('trim', explode("\n", $nodeConfig['ip'] ?? '')));
    if (empty($ips)) return '';
    
    $sni         = $nodeConfig['sni'] ?? '';
    $port        = $pkg['custom_port'] ?: 443;
    $path        = $pkg['custom_path'] ?: '/';
    $customSni   = $pkg['custom_sni'] ?? '';
    $relayMode   = (int)($pkg['relay_mode'] ?? $sub['relay_mode'] ?? 0);
    
    // Reality settings
    $realityDest = $pkg['reality_dest'] ?? '';
    $realitySni  = $pkg['reality_sni'] ?? '';
    $realityFp   = $pkg['reality_fingerprint'] ?? 'chrome';
    $realitySpx  = $pkg['reality_spider'] ?? '';
    
    $headerType  = $nodeConfig['header_type'] ?? 'none';
    
    $links = [];
    
    foreach ($ips as $ip) {
        switch ($protocol) {
            case 'vless':
                $links[] = build_vless_link($uuid, $ip, $port, $remark, $netType, $security, $sni, $path, $flow, $headerType, $realityDest, $realitySni, $realityFp, $realitySpx, $relayMode, $customSni);
                break;
            case 'trojan':
                $links[] = build_trojan_link($uuid, $ip, $port, $remark, $netType, $security, $sni, $path, $headerType, $relayMode, $customSni);
                break;
            case 'vmess':
                $links[] = build_vmess_link($uuid, $ip, $port, $remark, $netType, $security, $sni, $path, $headerType, $relayMode, $customSni);
                break;
        }
    }
    
    return implode("\n", $links);
}

function build_vless_link(string $uuid, string $ip, int $port, string $remark, string $netType, string $security, string $sni, string $path, string $flow, string $headerType, string $realityDest, string $realitySni, string $realityFp, string $realitySpx, int $relay, string $customSni): string {
    $params = [
        'type'     => $netType,
        'security' => $security,
    ];
    
    if ($security === 'tls' || $security === 'xtls') {
        $params['sni'] = $sni ?: $ip;
    }
    
    if ($security === 'reality') {
        $params['sni']  = $realitySni ?: $sni;
        $params['fp']   = $realityFp;
        $params['pbk']  = ''; // Public key from panel
        $params['sid']  = '';
        $params['spx']  = $realitySpx;
        if ($flow) $params['flow'] = $flow;
    }
    
    if ($security === 'xtls' && $flow) {
        $params['flow'] = $flow;
    }
    
    switch ($netType) {
        case 'ws':
            $params['path'] = $path;
            $params['host'] = $sni ?: $ip;
            break;
        case 'tcp':
            if ($headerType === 'http') {
                $params['headerType'] = 'http';
                $params['host'] = $sni ?: $ip;
            }
            break;
        case 'grpc':
            $params['serviceName'] = trim($path, '/');
            $params['mode'] = 'gun';
            break;
        case 'kcp':
            $params['headerType'] = $headerType ?: 'none';
            if (!empty($path)) $params['seed'] = $path;
            break;
    }
    
    // Relay/CDN mode
    if ($relay && $customSni) {
        $params['sni'] = $customSni;
        $params['host'] = $customSni;
        if ($security !== 'reality') $params['security'] = 'tls';
    }
    
    return 'vless://' . $uuid . '@' . $ip . ':' . $port . '?' . http_build_query($params) . '#' . urlencode($remark);
}

function build_trojan_link(string $password, string $ip, int $port, string $remark, string $netType, string $security, string $sni, string $path, string $headerType, int $relay, string $customSni): string {
    $params = [
        'type'     => $netType,
        'security' => $security ?: 'tls',
        'sni'      => $sni ?: $ip,
    ];
    
    switch ($netType) {
        case 'ws':
            $params['path'] = $path;
            $params['host'] = $sni ?: $ip;
            break;
        case 'grpc':
            $params['serviceName'] = trim($path, '/');
            $params['mode'] = 'gun';
            break;
        case 'tcp':
            if ($headerType === 'http') {
                $params['headerType'] = 'http';
                $params['host'] = $sni ?: $ip;
            }
            break;
    }
    
    if ($relay && $customSni) {
        $params['sni'] = $customSni;
        $params['host'] = $customSni;
    }
    
    return 'trojan://' . $password . '@' . $ip . ':' . $port . '?' . http_build_query($params) . '#' . urlencode($remark);
}

function build_vmess_link(string $uuid, string $ip, int $port, string $remark, string $netType, string $security, string $sni, string $path, string $headerType, int $relay, string $customSni): string {
    $config = [
        'v'    => '2',
        'ps'   => $remark,
        'add'  => $ip,
        'port' => $port,
        'id'   => $uuid,
        'aid'  => 0,
        'net'  => $netType,
        'type' => 'none',
        'host' => '',
        'path' => '',
        'tls'  => ($security === 'tls') ? 'tls' : '',
        'sni'  => $sni,
    ];
    
    switch ($netType) {
        case 'ws':
            $config['host'] = $sni ?: $ip;
            $config['path'] = $path;
            break;
        case 'tcp':
            if ($headerType === 'http') {
                $config['type'] = 'http';
                $config['host'] = $sni ?: $ip;
            }
            break;
        case 'grpc':
            $config['path'] = trim($path, '/');
            $config['type'] = $security;
            break;
        case 'kcp':
            $config['type'] = $headerType ?: 'none';
            $config['path'] = $path; // seed
            break;
    }
    
    if ($relay && $customSni) {
        $config['sni'] = $customSni;
        $config['host'] = $customSni;
        $config['tls'] = 'tls';
    }
    
    return 'vmess://' . base64_encode(json_encode($config, JSON_UNESCAPED_UNICODE));
}

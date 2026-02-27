<?php
/**
 * MahsaBot - Utility Helper Functions
 * 
 * @package MahsaBot
 */

/**
 * Format bytes to human-readable string (for Marzban - bytes)
 */
function format_bytes(float $bytes): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

/**
 * Format bytes to human-readable string (for x-ui - already in bytes)
 */
function format_traffic(float $bytes): string {
    if ($bytes <= 0) return '0 MB';
    if ($bytes < 1073741824) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    return round($bytes / 1073741824, 2) . ' GB';
}

/**
 * Generate a random UUID v4
 */
function generate_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Generate a random alphanumeric string
 */
function generate_token(int $length = 30): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $token = '';
    for ($i = 0; $i < $length; $i++) {
        $token .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $token;
}

/**
 * Generate a random password string
 */
function generate_password(int $length = 16): string {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Generate a random short ID (for Reality protocol)
 */
function generate_short_id(int $length = 8): string {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Perform an HTTP GET request
 */
function http_get(string $url, array $headers = []): string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('MahsaBot HTTP GET Error: ' . curl_error($ch) . ' URL: ' . $url);
        curl_close($ch);
        return '';
    }
    curl_close($ch);
    return $response ?: '';
}

/**
 * Perform an HTTP POST request
 */
function http_post(string $url, $data = [], array $headers = [], string $cookieFile = ''): string {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    
    if (is_array($data)) {
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    } else {
        $opts[CURLOPT_POSTFIELDS] = $data;
    }
    
    if (!empty($cookieFile)) {
        $opts[CURLOPT_COOKIEJAR]  = $cookieFile;
        $opts[CURLOPT_COOKIEFILE] = $cookieFile;
    }
    
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('MahsaBot HTTP POST Error: ' . curl_error($ch) . ' URL: ' . $url);
        curl_close($ch);
        return '';
    }
    curl_close($ch);
    return $response ?: '';
}

/**
 * Validate Telegram request IP (security check)
 */
function validate_telegram_ip(): bool {
    $telegramRanges = [
        '149.154.160.0/22', '149.154.164.0/22',
        '91.108.4.0/22', '91.108.56.0/22',
        '91.108.8.0/22', '95.161.64.0/20',
    ];
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    foreach ($telegramRanges as $range) {
        if (ip_in_cidr($clientIp, $range)) return true;
    }
    return false;
}

/**
 * Check if an IP is within a CIDR range
 */
function ip_in_cidr(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) $cidr .= '/32';
    list($subnet, $bits) = explode('/', $cidr, 2);
    $subnet = ip2long($subnet);
    $ip = ip2long($ip);
    $mask = -1 << (32 - (int)$bits);
    return ($ip & $mask) === ($subnet & $mask);
}

/**
 * Add QR code border/frame image
 */
function add_qr_border(string $qrPath, string $outputPath, int $size = 480): bool {
    $borderFile = __DIR__ . '/../assets/qr_frame.png';
    if (!file_exists($borderFile) || !file_exists($qrPath)) return false;
    
    $border = imagecreatefrompng($borderFile);
    $qr = imagecreatefrompng($qrPath);
    if (!$border || !$qr) return false;
    
    $borderW = imagesx($border);
    $borderH = imagesy($border);
    
    // Resize QR to fit inside border
    $qrResized = imagecreatetruecolor($size, $size);
    imagecopyresampled($qrResized, $qr, 0, 0, 0, 0, $size, $size, imagesx($qr), imagesy($qr));
    
    // Calculate center position
    $x = ($borderW - $size) / 2;
    $y = ($borderH - $size) / 2;
    
    imagecopy($border, $qrResized, (int)$x, (int)$y, 0, 0, $size, $size);
    imagepng($border, $outputPath);
    
    imagedestroy($border);
    imagedestroy($qr);
    imagedestroy($qrResized);
    
    return true;
}

/**
 * Number formatting with commas
 */
function format_price($amount): string {
    return number_format((int)$amount);
}

/**
 * Get a unique cookie file path for panel API requests
 * Prevents race conditions by using unique files per request
 */
function get_cookie_path(): string {
    $dir = sys_get_temp_dir() . '/mahsabot_cookies';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir . '/cookie_' . uniqid() . '.txt';
}

/**
 * Clean up a cookie file after use
 */
function cleanup_cookie(string $path): void {
    if (file_exists($path)) @unlink($path);
}

/**
 * Replace multiple placeholders in a string
 */
function fill_template(string $template, array $replacements): string {
    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

/**
 * Validate UUID format
 */
function is_valid_uuid(string $str): bool {
    return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $str);
}

/**
 * Safely get next port number (thread-safe with file locking)
 */
function get_next_port(int $start = 10000): int {
    $portFile = __DIR__ . '/../data/port_counter.txt';
    $dir = dirname($portFile);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    
    $fp = fopen($portFile, 'c+');
    if (!$fp) return random_int(10000, 60000);
    
    flock($fp, LOCK_EX);
    $current = (int)fread($fp, 10);
    $next = max($current + 1, $start);
    if ($next > 60000) $next = $start;
    
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string)$next);
    flock($fp, LOCK_UN);
    fclose($fp);
    
    return $next;
}

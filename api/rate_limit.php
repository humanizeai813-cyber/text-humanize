<?php
/**
 * Simple file-based per-IP rate limiter.
 * Returns true if the request is allowed, false if the limit is exceeded.
 *
 * Only Cloudflare's CF-Connecting-IP header is honoured (set it at the edge);
 * X-Forwarded-For is ignored because any client can spoof it to dodge limits.
 * IPs are stored hashed and counters expire with their window.
 */
function checkRateLimit(string $endpoint, int $limit = 10, int $windowSeconds = 60): bool {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip = trim(explode(',', $ip)[0]);

    $dir  = rtrim(sys_get_temp_dir(), '/\\');
    $file = $dir . '/rl_' . $endpoint . '_' . hash('sha256', $ip) . '.json';
    $now  = time();
    $data = ['count' => 0, 'start' => $now];

    if (file_exists($file)) {
        $stored = json_decode((string) @file_get_contents($file), true);
        if (is_array($stored) && ($now - ($stored['start'] ?? 0)) < $windowSeconds) {
            $data = $stored;
        }
    }

    $data['count']++;
    @file_put_contents($file, json_encode($data), LOCK_EX);

    // Occasionally remove counters from windows that have ended.
    if (mt_rand(1, 50) === 1) {
        foreach (glob($dir . '/rl_*.json') ?: [] as $old) {
            if ($now - (int) @filemtime($old) > 3600) @unlink($old);
        }
    }

    return $data['count'] <= $limit;
}

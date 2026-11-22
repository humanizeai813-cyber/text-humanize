<?php
/**
 * Cache — File-based TTL cache for API responses.
 *
 * Keys are hashed with SHA-256 and stored as JSON in the system temp directory
 * (hc_{hash}.json). Expired entries are purged periodically so cached results
 * never outlive the retention period stated in the privacy policy.
 */
class Cache
{
    private static string $prefix = 'hc_';

    public static function get(string $key): ?array
    {
        $path = self::path($key);
        if (!file_exists($path)) return null;

        $raw = @file_get_contents($path);
        if ($raw === false) return null;

        $data = json_decode($raw, true);
        if (!is_array($data)) return null;

        // Check TTL
        if (isset($data['_expires']) && time() > $data['_expires']) {
            @unlink($path);
            return null;
        }

        return $data['_payload'] ?? null;
    }

    public static function set(string $key, array $payload, int $ttlSeconds = 3600): void
    {
        $data = [
            '_expires' => time() + $ttlSeconds,
            '_payload' => $payload,
        ];
        @file_put_contents(self::path($key), json_encode($data), LOCK_EX);

        if (mt_rand(1, 25) === 1) {
            self::purgeExpired();
        }
    }

    /** Delete every expired cache file. */
    public static function purgeExpired(): void
    {
        foreach (glob(self::dir() . '/' . self::$prefix . '*.json') ?: [] as $file) {
            $raw  = @file_get_contents($file);
            $data = $raw === false ? null : json_decode($raw, true);
            if (!is_array($data) || !isset($data['_expires']) || time() > $data['_expires']) {
                @unlink($file);
            }
        }
    }

    public static function exists(string $key): bool
    {
        return self::get($key) !== null;
    }

    public static function delete(string $key): void
    {
        @unlink(self::path($key));
    }

    private static function dir(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\');
    }

    private static function path(string $key): string
    {
        return self::dir() . '/' . self::$prefix . hash('sha256', $key) . '.json';
    }
}

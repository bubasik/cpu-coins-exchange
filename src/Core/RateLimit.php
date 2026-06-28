<?php
declare(strict_types=1);

namespace App\Core;

final class RateLimit
{
    public static function hit(string $key, int $max, int $windowSec): bool
    {
        if (Redis::isAvailable()) {
            $redis = Redis::client();
            $k = 'rate:' . $key . ':' . floor(time() / $windowSec);
            $count = (int)$redis->incr($k);
            if ($count === 1) $redis->expire($k, $windowSec);
            return $count <= $max;
        }

        // File-based fallback (per-process approximation)
        $file = sys_get_temp_dir() . '/ys_rate_' . md5($key) . '_' . floor(time() / $windowSec);
        $count = file_exists($file) ? (int)file_get_contents($file) : 0;
        $count++;
        file_put_contents($file, (string)$count, LOCK_EX);
        return $count <= $max;
    }

    public static function byIp(string $action, int $max, int $windowSec): bool
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ip = explode(',', $ip)[0];
        return self::hit($action . ':' . $ip, $max, $windowSec);
    }
}

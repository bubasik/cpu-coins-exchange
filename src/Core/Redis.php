<?php
declare(strict_types=1);

namespace App\Core;

use Predis\Client;
use Predis\Connection\ConnectionException;

/**
 * Redis wrapper with file-based fallback.
 *
 * If Redis is unreachable, falls back to a simple file-based store in
 * storage/cache/. This is NOT for production (no locking, slow), but allows
 * the app to run for development/testing without Redis.
 */
final class Redis
{
    private static ?Client $client = null;
    private static ?bool $available = null;

    public static function client(): Client
    {
        if (self::$client === null) {
            self::$client = new Client([
                'scheme' => 'tcp',
                'host' => Config::get('REDIS_HOST', '127.0.0.1'),
                'port' => (int)Config::get('REDIS_PORT', 6379),
                'database' => (int)Config::get('REDIS_DB', 0),
                'password' => Config::get('REDIS_PASSWORD') ?: null,
            ]);
        }
        return self::$client;
    }

    public static function isAvailable(): bool
    {
        if (self::$available !== null) return self::$available;
        try {
            self::client()->ping();
            self::$available = true;
        } catch (\Throwable $e) {
            self::$available = false;
            error_log("Redis unavailable, using file fallback: " . $e->getMessage());
        }
        return self::$available;
    }

    public static function get(string $key): mixed
    {
        if (self::isAvailable()) {
            $v = self::client()->get($key);
            return $v === null ? null : json_decode($v, true);
        }
        return self::fileGet($key);
    }

    public static function set(string $key, mixed $value, int $ttl = 0): void
    {
        if (self::isAvailable()) {
            $payload = json_encode($value);
            if ($ttl > 0) self::client()->setex($key, $ttl, $payload);
            else self::client()->set($key, $payload);
            return;
        }
        self::fileSet($key, $value, $ttl);
    }

    public static function del(string $key): void
    {
        if (self::isAvailable()) {
            self::client()->del($key);
            return;
        }
        self::fileDel($key);
    }

    // ===== File-based fallback =====

    private static string $cacheDir = '';

    private static function cacheDir(): string
    {
        if (self::$cacheDir === '') {
            self::$cacheDir = dirname(__DIR__, 2) . '/storage/cache/data';
            if (!is_dir(self::$cacheDir)) {
                @mkdir(self::$cacheDir, 0775, true);
            }
        }
        return self::$cacheDir;
    }

    private static function fileKey(string $key): string
    {
        return self::cacheDir() . '/' . preg_replace('/[^a-zA-Z0-9_\-:.]/', '_', $key) . '.json';
    }

    private static function fileGet(string $key): mixed
    {
        $path = self::fileKey($key);
        if (!file_exists($path)) return null;
        $data = file_get_contents($path);
        if ($data === false) return null;
        $obj = json_decode($data, true);
        if (!is_array($obj)) return null;
        // Check TTL
        if (!empty($obj['_expires_at']) && $obj['_expires_at'] < time()) {
            @unlink($path);
            return null;
        }
        return $obj['value'] ?? null;
    }

    private static function fileSet(string $key, mixed $value, int $ttl = 0): void
    {
        $path = self::fileKey($key);
        $payload = [
            'value' => $value,
            '_expires_at' => $ttl > 0 ? time() + $ttl : 0,
            '_set_at' => time(),
        ];
        file_put_contents($path, json_encode($payload), LOCK_EX);
    }

    private static function fileDel(string $key): void
    {
        $path = self::fileKey($key);
        if (file_exists($path)) @unlink($path);
    }
}

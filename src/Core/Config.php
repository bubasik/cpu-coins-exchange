<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $cache = [];

    public static function load(): void
    {
        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '#')) continue;
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                $_ENV[$k] = $v;
                putenv("$k=$v");
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) return self::$cache[$key];
        $val = $_ENV[$key] ?? getenv($key) ?: null;
        if ($val === false || $val === null) return $default;
        // cast bool/null
        $lower = strtolower($val);
        if ($lower === 'true') return true;
        if ($lower === 'false') return false;
        if ($lower === 'null') return null;
        return self::$cache[$key] = $val;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int)self::get($key, $default);
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        return (float)self::get($key, $default);
    }
}

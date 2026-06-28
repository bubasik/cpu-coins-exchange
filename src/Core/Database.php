<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $path = Config::get('DB_PATH', 'storage/exchange.sqlite');
            $fullPath = dirname(__DIR__, 2) . '/' . $path;
            $dir = dirname($fullPath);
            if (!is_dir($dir)) mkdir($dir, 0700, true);

            $dsn = 'sqlite:' . $fullPath;
            try {
                self::$pdo = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                self::$pdo->exec('PRAGMA journal_mode = WAL');
                self::$pdo->exec('PRAGMA foreign_keys = ON');
                self::$pdo->exec('PRAGMA busy_timeout = 5000');
            } catch (PDOException $e) {
                throw new \RuntimeException('DB connection failed: ' . $e->getMessage());
            }
        }
        return self::$pdo;
    }

    public static function migrate(): void
    {
        $sqlPath = dirname(__DIR__, 2) . '/sql/schema.sql';
        $sql = file_get_contents($sqlPath);
        self::pdo()->exec($sql);
    }
}

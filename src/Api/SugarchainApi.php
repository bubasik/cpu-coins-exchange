<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Config;
use App\Network\SugarchainNetwork;

final class SugarchainApi
{
    private static ?ApiClient $client = null;
    private static ?SugarchainNetwork $network = null;

    public static function client(): ApiClient
    {
        if (self::$client === null) {
            $url = trim(Config::get('SUGAR_API', 'https://api.sugarchain.org'));
            $url = rtrim($url, '/');
            self::$client = new ApiClient($url, 15, 2);
        }
        return self::$client;
    }

    public static function network(): SugarchainNetwork
    {
        if (self::$network === null) self::$network = new SugarchainNetwork();
        return self::$network;
    }

    public static function confirmationsRequired(): int
    {
        return (int)Config::get('SUGAR_CONFIRMATIONS', 30);
    }

    public static function decimals(): int { return 8; }
    public static function symbol(): string { return 'SUGAR'; }
    public static function name(): string { return 'Sugarchain'; }
}

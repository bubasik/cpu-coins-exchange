<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Config;
use App\Network\YentenNetwork;

final class YentenApi
{
    private static ?ApiClient $client = null;
    private static ?YentenNetwork $network = null;

    public static function client(): ApiClient
    {
        if (self::$client === null) {
            // Trim+trim to remove any whitespace/CRLF (esp. when .env has Windows line endings)
            $url = trim(Config::get('YTN_API', 'https://api.yentencoin.info'));
            $url = rtrim($url, '/');
            self::$client = new ApiClient($url, 15, 2);
        }
        return self::$client;
    }

    public static function network(): YentenNetwork
    {
        if (self::$network === null) self::$network = new YentenNetwork();
        return self::$network;
    }

    public static function confirmationsRequired(): int
    {
        return (int)Config::get('YTN_CONFIRMATIONS', 6);
    }

    public static function decimals(): int { return 8; }
    public static function symbol(): string { return 'YTN'; }
    public static function name(): string { return 'Yenten'; }
}

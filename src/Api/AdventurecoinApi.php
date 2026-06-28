<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Config;
use App\Network\AdventurecoinNetwork;

final class AdventurecoinApi
{
    private static ?ApiClient $client = null;
    private static ?AdventurecoinNetwork $network = null;

    public static function client(): ApiClient
    {
        if (self::$client === null) {
            $url = trim(Config::get('ADVC_API', 'https://api2.adventurecoin.quest'));
            $url = rtrim($url, '/');
            self::$client = new ApiClient($url, 15, 8);
        }
        return self::$client;
    }

    public static function network(): AdventurecoinNetwork
    {
        if (self::$network === null) self::$network = new AdventurecoinNetwork();
        return self::$network;
    }

    public static function confirmationsRequired(): int
    {
        return (int)Config::get('ADVC_CONFIRMATIONS', 6);
    }

    public static function decimals(): int { return 8; }
    public static function symbol(): string { return 'ADVC'; }
    public static function name(): string { return 'Adventurecoin'; }
}

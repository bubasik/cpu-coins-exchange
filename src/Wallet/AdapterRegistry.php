<?php
declare(strict_types=1);

namespace App\Wallet;

final class AdapterRegistry
{
    private static array $adapters = [];

    public static function get(string $coin): CoinAdapter
    {
        $coin = strtoupper($coin);
        if (isset(self::$adapters[$coin])) return self::$adapters[$coin];

        return match ($coin) {
            'YTN', 'YENTEN', 'YENTENCOIN'   => self::$adapters['YTN']   = new YentenAdapter(),
            'SUGAR', 'SUGARCHAIN'           => self::$adapters['SUGAR'] = new SugarchainAdapter(),
            'ADVC', 'ADVENTURECOIN', 'ADVENTURE' => self::$adapters['ADVC'] = new AdventurecoinAdapter(),
            default => throw new \InvalidArgumentException("Unknown coin: $coin"),
        };
    }

    public static function all(): array
    {
        return [
            'YTN'   => self::get('YTN'),
            'SUGAR' => self::get('SUGAR'),
            'ADVC'  => self::get('ADVC'),
        ];
    }

    /**
     * List of (symbol => display_name) for UI dropdowns.
     */
    public static function listForUi(): array
    {
        return [
            'YTN'   => 'Yenten (YTN)',
            'SUGAR' => 'Sugarchain (SUGAR)',
            'ADVC'  => 'Adventurecoin (ADVC)',
        ];
    }
}

<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Config;

/**
 * Trade pair configuration via .env.
 *
 *   TRADE_PAIRS=YTN/SUGAR,YTN/ADVC,SUGAR/ADVC
 *   TRADE_PAIR_DEFAULT=YTN/SUGAR
 *   TRADE_FEE_PERCENT=0.2
 *   TRADE_FEE_YTN_SUGAR=0.15  (per-pair override, optional)
 */
final class TradePairRegistry
{
    private static ?array $pairsCache = null;

    public static function all(): array
    {
        if (self::$pairsCache !== null) return self::$pairsCache;

        $envPairs = Config::get('TRADE_PAIRS', 'YTN/SUGAR');
        $pairs = [];
        foreach (explode(',', $envPairs) as $p) {
            $p = trim($p);
            if (empty($p)) continue;
            $parts = explode('/', $p, 2);
            if (count($parts) !== 2) continue;
            $base = strtoupper(trim($parts[0]));
            $quote = strtoupper(trim($parts[1]));
            if (empty($base) || empty($quote)) continue;

            $pair = self::makePair($base, $quote);
            $pairs[$pair->key] = $pair;
        }

        if (empty($pairs)) {
            $pair = self::makePair('YTN', 'SUGAR');
            $pairs[$pair->key] = $pair;
        }

        self::$pairsCache = $pairs;
        return $pairs;
    }

    public static function get(string $key): ?TradePair
    {
        $key = strtoupper($key);
        return self::all()[$key] ?? null;
    }

    public static function default(): TradePair
    {
        $defaultKey = strtoupper(Config::get('TRADE_PAIR_DEFAULT', ''));
        if ($defaultKey && isset(self::all()[$defaultKey])) {
            return self::all()[$defaultKey];
        }
        return array_values(self::all())[0];
    }

    public static function listKeys(): array
    {
        return array_keys(self::all());
    }

    public static function listForUi(): array
    {
        $out = [];
        foreach (self::all() as $key => $pair) {
            $out[$key] = $pair->label;
        }
        return $out;
    }

    private static function makePair(string $base, string $quote): TradePair
    {
        $key = "$base/$quote";
        $envFeeKey = 'TRADE_FEE_' . $base . '_' . $quote;
        $fee = (float)Config::get($envFeeKey, Config::get('TRADE_FEE_PERCENT', '0.2'));
        $minAmount = (float)Config::get('TRADE_MIN_AMOUNT', '0.001');

        return new TradePair(
            key: $key,
            base: $base,
            quote: $quote,
            label: "$base / $quote",
            feePercent: $fee,
            minAmount: $minAmount
        );
    }
}

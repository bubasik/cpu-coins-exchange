<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use App\Core\Redis;
use App\Wallet\AdapterRegistry;

/**
 * Cached network statistics for all coins.
 *
 * The /info endpoint is expensive (~1s per coin × 3 coins = 3s on home page).
 * Cache it for 60 seconds in Redis (or file fallback). Tradeoff: stats may be
 * up to 60s stale, but page loads instantly.
 */
final class NetworkStats
{
    private const CACHE_TTL = 60;
    private const CACHE_KEY = 'network_stats:all';

    public function getAllInfo(): array
    {
        $cached = Redis::get(self::CACHE_KEY);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $result = [];
        foreach (AdapterRegistry::all() as $symbol => $adapter) {
            try {
                $info = $adapter->api()->getInfo();
                $result[$symbol] = [
                    'symbol'      => $symbol,
                    'name'        => $adapter->name(),
                    'api_url'     => $this->getApiUrl($symbol),
                    'blocks'      => $info['blocks'] ?? null,
                    'difficulty'  => $info['difficulty'] ?? null,
                    'nethash'     => $info['nethash'] ?? null,
                    'supply'      => $info['supply'] ?? null,
                    'bestblockhash' => $info['bestblockhash'] ?? null,
                    'chain'       => $info['chain'] ?? null,
                    'reward'      => $info['reward'] ?? null,
                    'fetched_at'  => time(),
                ];
            } catch (\Throwable $e) {
                $result[$symbol] = [
                    'symbol'  => $symbol,
                    'name'    => $adapter->name(),
                    'error'   => $e->getMessage(),
                    'fetched_at' => time(),
                ];
            }
        }

        Redis::set(self::CACHE_KEY, $result, self::CACHE_TTL);
        return $result;
    }

    public function getInfo(string $coin): array
    {
        $all = $this->getAllInfo();
        return $all[strtoupper($coin)] ?? [];
    }

    public function refresh(): void
    {
        Redis::del(self::CACHE_KEY);
    }

    public function getFeeRate(string $coin): ?int
    {
        $coin = strtoupper($coin);
        $cacheKey = "network_stats:fee:$coin";
        $cached = Redis::get($cacheKey);
        if ($cached !== null) return (int)$cached;

        try {
            $adapter = AdapterRegistry::get($coin);
            $fee = $adapter->api()->getFee();
            $rate = (int)($fee['feerate'] ?? 1000);
            Redis::set($cacheKey, $rate, 30);
            return $rate;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getApiUrl(string $symbol): string
    {
        return match ($symbol) {
            'YTN'   => Config::get('YTN_API', 'https://api.yentencoin.info'),
            'SUGAR' => Config::get('SUGAR_API', 'https://api.sugarchain.org'),
            'ADVC'  => Config::get('ADVC_API', 'https://api2.adventurecoin.quest'),
            default => Config::get("{$symbol}_API", ''),
        };
    }
}

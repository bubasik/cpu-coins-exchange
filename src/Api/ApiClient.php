<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Redis;

/**
 * Base HTTP client for sugarchain-project/api-server (Yenten, Sugarchain, Adventurecoin).
 *
 * Endpoints (GET unless noted):
 *   /info                          - chain info {blocks, difficulty, supply, ...}
 *   /height/{height}               - block by height
 *   /block/{hash}                  - block by hash
 *   /transaction/{txid}            - tx detail with hex
 *   /balance/{address}             - {balance, received} in satoshis
 *   /unspent/{address}             - array of UTXOs
 *   /history/{address}             - {tx: [...txids], txcount}
 *   /mempool/{address}             - mempool txs for address
 *   /mempool                       - all mempool txs
 *   /fee                           - {feerate, blocks}
 *   /supply                        - {supply, height, halvings}
 *   /decode/{raw}                  - decoded raw tx
 *   POST /broadcast (form: raw=hex) - broadcast signed tx, returns txid
 *
 * Caching (anti-rate-limit):
 *   - /info, /fee, /supply           → 60s (network-wide data, changes slowly)
 *   - /balance, /unspent, /history   → 15s (per-address, polled by workers)
 *   - /transaction                   → 30s (per-tx, polled after deposits)
 *   - /decode                        → no cache (debug only)
 *   - /broadcast                     → no cache (write op)
 *
 * Throttle: 2 requests/sec max (was 8/sec, lowered to avoid API bans).
 */
class ApiClient
{
    public function __construct(
        private string $baseUrl,
        private int $timeoutSec = 15,
        private int $ratePerSec = 2
    ) {}

    /**
     * Cache TTLs (seconds). Set to 0 to disable caching for a specific endpoint.
     */
    private const CACHE_TTL = [
        'info' => 60,
        'fee' => 60,
        'supply' => 60,
        'balance' => 15,
        'unspent' => 15,
        'history' => 15,
        'transaction' => 30,
    ];

    public function get(string $path): array
    {
        $this->throttle();
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'YentenSugarExchange/1.0',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("API GET $url failed: $err");
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("API GET $url: invalid JSON ($code)");
        }
        return $data;
    }

    public function post(string $path, array $form = []): array
    {
        $this->throttle();
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_USERAGENT => 'YentenSugarExchange/1.0',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("API POST $url failed: $err");
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("API POST $url: invalid JSON ($code)");
        }
        return $data;
    }

    /** Helper: get required result field, throw on API error */
    public function getResult(string $path): array
    {
        // Check cache first
        $cacheKey = $this->cacheKey($path);
        $ttl = $this->cacheTtl($path);
        if ($cacheKey !== null && $ttl > 0) {
            $cached = Redis::get($cacheKey);
            if ($cached !== null && is_array($cached)) {
                return $cached;
            }
        }

        // Cache miss — fetch from API
        $r = $this->get($path);
        if (!empty($r['error'])) {
            throw new \RuntimeException("API error on $path: " . json_encode($r['error']));
        }
        $result = $r['result'] ?? [];

        // Store in cache
        if ($cacheKey !== null && $ttl > 0) {
            Redis::set($cacheKey, $result, $ttl);
        }
        return $result;
    }

    /**
     * Force refresh cache for a path (bypass cache on next read).
     */
    public function invalidateCache(string $path): void
    {
        $cacheKey = $this->cacheKey($path);
        if ($cacheKey !== null) {
            Redis::del($cacheKey);
        }
    }

    /**
     * Build cache key from path. Returns null if endpoint should not be cached.
     */
    private function cacheKey(string $path): ?string
    {
        // Match path patterns
        if (preg_match('#^/info$#', $path)) return 'api:' . md5($this->baseUrl) . ':info';
        if (preg_match('#^/fee$#', $path)) return 'api:' . md5($this->baseUrl) . ':fee';
        if (preg_match('#^/supply$#', $path)) return 'api:' . md5($this->baseUrl) . ':supply';
        if (preg_match('#^/balance/(.+)$#', $path, $m)) return 'api:' . md5($this->baseUrl) . ':balance:' . $m[1];
        if (preg_match('#^/unspent/(.+)$#', $path, $m)) return 'api:' . md5($this->baseUrl) . ':unspent:' . $m[1];
        if (preg_match('#^/history/(.+)$#', $path, $m)) return 'api:' . md5($this->baseUrl) . ':history:' . $m[1];
        if (preg_match('#^/transaction/(.+)$#', $path, $m)) return 'api:' . md5($this->baseUrl) . ':tx:' . $m[1];
        return null;  // /decode, /broadcast, /height, /block — no cache
    }

    private function cacheTtl(string $path): int
    {
        if (preg_match('#^/info$#', $path)) return self::CACHE_TTL['info'];
        if (preg_match('#^/fee$#', $path)) return self::CACHE_TTL['fee'];
        if (preg_match('#^/supply$#', $path)) return self::CACHE_TTL['supply'];
        if (preg_match('#^/balance/#', $path)) return self::CACHE_TTL['balance'];
        if (preg_match('#^/unspent/#', $path)) return self::CACHE_TTL['unspent'];
        if (preg_match('#^/history/#', $path)) return self::CACHE_TTL['history'];
        if (preg_match('#^/transaction/#', $path)) return self::CACHE_TTL['transaction'];
        return 0;
    }

    public function getInfo(): array { return $this->getResult('/info'); }

    public function getBalance(string $address): array
    {
        $address = trim($address);
        return $this->getResult('/balance/' . $address);
    }

    public function getUnspent(string $address): array
    {
        $address = trim($address);
        return $this->getResult('/unspent/' . $address);
    }

    public function getHistory(string $address): array
    {
        $address = trim($address);
        return $this->getResult('/history/' . $address);
    }

    public function getTransaction(string $txid): array
    {
        $txid = trim($txid);
        return $this->getResult('/transaction/' . $txid);
    }

    public function getFee(): array { return $this->getResult('/fee'); }

    public function decodeRaw(string $hex): array
    {
        $hex = trim($hex);
        // No cache — debug endpoint
        $r = $this->get('/decode/' . $hex);
        if (!empty($r['error'])) {
            throw new \RuntimeException("API error on /decode: " . json_encode($r['error']));
        }
        return $r['result'] ?? [];
    }

    public function broadcast(string $hex): string
    {
        // No cache — write operation.
        $r = $this->post('/broadcast', ['raw' => $hex]);
        if (!empty($r['error'])) {
            throw new \RuntimeException("Broadcast failed: " . json_encode($r['error']));
        }
        $txid = $r['result'] ?? null;
        if (!is_string($txid)) {
            throw new \RuntimeException("Broadcast: unexpected response: " . json_encode($r));
        }
        return $txid;
    }

    /**
     * Invalidate cache for a specific address (balance, unspent, history).
     * Call this after broadcast to ensure fresh UTXO data on next read.
     */
    public function invalidateAddressCache(string $address): void
    {
        $address = trim($address);
        $prefix = 'api:' . md5($this->baseUrl) . ':';
        Redis::del($prefix . 'balance:' . $address);
        Redis::del($prefix . 'unspent:' . $address);
        Redis::del($prefix . 'history:' . $address);
    }

    /**
     * Invalidate all cached data for this API (use after large state changes).
     */
    public function invalidateAllCache(): void
    {
        $prefix = 'api:' . md5($this->baseUrl) . ':';
        // Redis::del doesn't support pattern matching, so we delete known keys
        // In production with Redis you'd use SCAN + DEL, but for simplicity:
        Redis::del($prefix . 'info');
        Redis::del($prefix . 'fee');
        Redis::del($prefix . 'supply');
    }

    public function getCurrentHeight(): int
    {
        $info = $this->getInfo();
        return (int)($info['blocks'] ?? 0);
    }

    public function getConfirmations(int $txHeight, int $currentHeight): int
    {
        if ($txHeight <= 0) return 0;
        return max(0, $currentHeight - $txHeight + 1);
    }

    private float $lastCallTs = 0.0;
    private function throttle(): void
    {
        if ($this->ratePerSec <= 0) return;
        $minInterval = 1.0 / $this->ratePerSec;
        $now = microtime(true);
        $elapsed = $now - $this->lastCallTs;
        if ($elapsed < $minInterval) {
            usleep((int)(($minInterval - $elapsed) * 1_000_000));
        }
        $this->lastCallTs = microtime(true);
    }
}

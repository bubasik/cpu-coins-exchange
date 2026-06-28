<?php
declare(strict_types=1);

namespace App\Api;

/**
 * Base HTTP client for sugarchain-project/api-server (used by both Yenten and Sugarchain).
 * Endpoints (GET unless noted):
 *   /info                          - chain info {blocks, difficulty, supply, ...}
 *   /height/{height}               - block by height
 *   /block/{hash}                  - block by hash
 *   /transaction/{txid}            - tx detail with hex
 *   /balance/{address}             - {balance, received} in satoshis
 *   /unspent/{address}             - array of UTXOs [{txid, index, script, value, height}]
 *   /history/{address}             - {tx: [...txids], txcount}
 *   /mempool/{address}             - mempool txs for address
 *   /mempool                       - all mempool txs
 *   /fee                           - {feerate, blocks}
 *   /supply                        - {supply, height, halvings}
 *   /decode/{raw}                  - decoded raw tx
 *   POST /broadcast (form: raw=hex) - broadcast signed tx, returns txid
 */
class ApiClient
{
    public function __construct(
        private string $baseUrl,
        private int $timeoutSec = 15,
        private int $ratePerSec = 10
    ) {}

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
        $r = $this->get($path);
        if (!empty($r['error'])) {
            throw new \RuntimeException("API error on $path: " . json_encode($r['error']));
        }
        return $r['result'] ?? [];
    }

    public function getInfo(): array { return $this->getResult('/info'); }

    public function getBalance(string $address): array
    {
        // Trim to remove any whitespace/CRLF that might be in DB-stored addresses
        $address = trim($address);
        // Don't urlencode - base58 chars are URL-safe, encoding can confuse some servers
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
        // Hex string - safe to use directly
        return $this->getResult('/decode/' . $hex);
    }

    public function broadcast(string $hex): string
    {
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

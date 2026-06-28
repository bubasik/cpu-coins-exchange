<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Redis;
use App\Core\Response;
use App\Core\View;
use App\Core\Request;
use App\Wallet\AdapterRegistry;
use App\Wallet\HdWallet;

/**
 * Status controller — health checks for all coin APIs, DB, Redis, and PHP environment.
 *
 * Routes:
 *   GET /status       — HTML page with live status dashboard
 *   GET /api/status   — JSON endpoint for monitoring (returns all checks)
 *   GET /api/status/{coin} — JSON status for a single coin
 */
final class StatusController
{
    /** Timeout for API calls during health check (seconds). */
    private const API_TIMEOUT = 8;

    /**
     * HTML status page.
     */
    public function page(Request $req, array $params): void
    {
        $status = $this->collectAllStatus();
        View::render('status', [
            'status' => $status,
            'generatedAt' => time(),
        ]);
    }

    /**
     * JSON endpoint — full status.
     */
    public function api(Request $req, array $params): void
    {
        $status = $this->collectAllStatus();
        Response::json($status);
    }

    /**
     * JSON endpoint — single coin status.
     */
    public function apiCoin(Request $req, array $params): void
    {
        $coin = strtoupper($params['coin'] ?? '');
        try {
            $adapter = AdapterRegistry::get($coin);
        } catch (\Throwable $e) {
            Response::json(['error' => "Unknown coin: $coin"], 404);
            return;
        }
        Response::json($this->checkCoin($adapter));
    }

    /**
     * Collect status for all subsystems.
     */
    private function collectAllStatus(): array
    {
        $start = microtime(true);

        $status = [
            'generated_at' => date('c'),
            'app_name' => Config::get('APP_NAME', 'Yenten-Sugar Exchange'),
            'environment' => Config::get('APP_ENV', 'production'),
            'php_version' => PHP_VERSION,
            'coins' => [],
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'storage' => $this->checkStorage(),
            'extensions' => $this->checkExtensions(),
            'summary' => [
                'total' => 0,
                'healthy' => 0,
                'degraded' => 0,
                'down' => 0,
            ],
        ];

        // Check each coin
        foreach (AdapterRegistry::all() as $symbol => $adapter) {
            $coinStatus = $this->checkCoin($adapter);
            $status['coins'][$symbol] = $coinStatus;
            $status['summary']['total']++;
            if ($coinStatus['status'] === 'healthy') $status['summary']['healthy']++;
            elseif ($coinStatus['status'] === 'degraded') $status['summary']['degraded']++;
            else $status['summary']['down']++;
        }

        $status['response_time_ms'] = (int)((microtime(true) - $start) * 1000);

        return $status;
    }

    /**
     * Check a single coin API.
     */
    private function checkCoin(\App\Wallet\CoinAdapter $adapter): array
    {
        $start = microtime(true);
        $result = [
            'symbol' => $adapter->symbol(),
            'name' => $adapter->name(),
            'status' => 'down',
            'api_url' => '',
            'response_time_ms' => 0,
            'blocks' => null,
            'difficulty' => null,
            'nethash' => null,
            'supply' => null,
            'best_block_hash' => null,
            'fee_rate' => null,
            'confirmations_required' => $adapter->confirmationsRequired(),
            'hot_wallet_configured' => !empty($adapter->hotWalletAddress())
                && strpos($adapter->hotWalletAddress(), '...') === false,
            'xprv_configured' => !empty($adapter->xprv())
                && strpos($adapter->xprv(), 'your-') === false,
            'xpub_configured' => !empty($adapter->xpub())
                && strpos($adapter->xpub(), 'your-') === false,
            'hot_wallet_balance' => null,
            'error' => null,
        ];

        // Get API URL from the client
        try {
            $ref = new \ReflectionClass($adapter->api());
            // ApiClient stores baseUrl as private — try to read via probing /info
            $result['api_url'] = $this->probeApiUrl($adapter);
        } catch (\Throwable $e) {
            // ignore
        }

        // Call /info (cached via NetworkStats, 60s TTL)
        try {
            $stats = new \App\Service\NetworkStats();
            $info = $stats->getInfo($adapter->symbol());
            if (isset($info['error'])) {
                throw new \RuntimeException($info['error']);
            }
            $result['blocks'] = $info['blocks'] ?? null;
            $result['difficulty'] = $info['difficulty'] ?? null;
            $result['nethash'] = $info['nethash'] ?? null;
            $result['supply'] = $info['supply'] ?? null;
            $result['best_block_hash'] = $info['bestblockhash'] ?? null;
            $result['status'] = 'healthy';
        } catch (\Throwable $e) {
            $result['error'] = 'API /info failed: ' . $e->getMessage();
            $result['response_time_ms'] = (int)((microtime(true) - $start) * 1000);
            return $result;
        }

        // Call /fee (cached 30s via NetworkStats)
        try {
            $stats = new \App\Service\NetworkStats();
            $fee = $stats->getFeeRate($adapter->symbol());
            $result['fee_rate'] = $fee;
            if ($fee === null) {
                $result['status'] = 'degraded';
                $result['error'] = '/fee failed';
            }
        } catch (\Throwable $e) {
            $result['status'] = 'degraded';
            $result['error'] = '/fee failed: ' . $e->getMessage();
        }

        // Check hot wallet balance (only if address configured)
        if ($result['hot_wallet_configured']) {
            try {
                $result['hot_wallet_balance'] = $adapter->getBalanceSat($adapter->hotWalletAddress());
            } catch (\Throwable $e) {
                // Non-fatal
                $result['hot_wallet_balance'] = null;
            }
        }

        $result['response_time_ms'] = (int)((microtime(true) - $start) * 1000);
        return $result;
    }

    /**
     * Probe API URL by reading Config directly.
     */
    private function probeApiUrl(\App\Wallet\CoinAdapter $adapter): string
    {
        $sym = $adapter->symbol();
        $key = match ($sym) {
            'YTN'   => 'YTN_API',
            'SUGAR' => 'SUGAR_API',
            'ADVC'  => 'ADVC_API',
            default => "{$sym}_API",
        };
        $default = match ($sym) {
            'YTN'   => 'https://api.yentencoin.info',
            'SUGAR' => 'https://api.sugarchain.org',
            'ADVC'  => 'https://api2.adventurecoin.quest',
            default => '',
        };
        return Config::get($key, $default);
    }

    /**
     * Check SQLite database.
     */
    private function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            $pdo = Database::pdo();
            $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $swaps = (int)$pdo->query('SELECT COUNT(*) FROM swap_orders')->fetchColumn();
            $orders = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
            $dbPath = Config::get('DB_PATH', 'storage/exchange.sqlite');
            $sizeBytes = file_exists(dirname(__DIR__, 2) . '/' . $dbPath)
                ? filesize(dirname(__DIR__, 2) . '/' . $dbPath)
                : 0;
            return [
                'status' => 'healthy',
                'type' => 'SQLite',
                'path' => $dbPath,
                'size_bytes' => $sizeBytes,
                'size_human' => $this->formatBytes($sizeBytes),
                'users' => $count,
                'swap_orders' => $swaps,
                'trade_orders' => $orders,
                'response_time_ms' => (int)((microtime(true) - $start) * 1000),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'down',
                'error' => $e->getMessage(),
                'response_time_ms' => (int)((microtime(true) - $start) * 1000),
            ];
        }
    }

    /**
     * Check Redis.
     */
    private function checkRedis(): array
    {
        $start = microtime(true);
        $host = Config::get('REDIS_HOST', '127.0.0.1');
        $port = (int)Config::get('REDIS_PORT', 6379);
        try {
            if (!Redis::isAvailable()) {
                return [
                    'status' => 'degraded',
                    'host' => $host,
                    'port' => $port,
                    'mode' => 'file_fallback',
                    'error' => 'Redis unavailable, using file-based cache fallback',
                    'response_time_ms' => (int)((microtime(true) - $start) * 1000),
                ];
            }
            $pong = Redis::client()->ping();
            $info = Redis::client()->info('server');
            return [
                'status' => 'healthy',
                'host' => $host,
                'port' => $port,
                'mode' => 'redis',
                'version' => $info['Server']['redis_version'] ?? null,
                'ping' => is_string($pong) ? $pong : 'OK',
                'response_time_ms' => (int)((microtime(true) - $start) * 1000),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'down',
                'host' => $host,
                'port' => $port,
                'mode' => 'file_fallback',
                'error' => $e->getMessage(),
                'response_time_ms' => (int)((microtime(true) - $start) * 1000),
            ];
        }
    }

    /**
     * Check storage directories.
     */
    private function checkStorage(): array
    {
        $base = dirname(__DIR__, 2);
        $dirs = ['storage/logs', 'storage/cache', 'storage/uploads', 'storage/backups'];
        $result = ['status' => 'healthy', 'directories' => []];
        foreach ($dirs as $d) {
            $path = "$base/$d";
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            $result['directories'][$d] = [
                'exists' => $exists,
                'writable' => $writable,
            ];
            if (!$writable) {
                $result['status'] = 'degraded';
            }
        }
        return $result;
    }

    /**
     * Check required PHP extensions.
     */
    private function checkExtensions(): array
    {
        $required = ['pdo', 'pdo_sqlite', 'curl', 'mbstring', 'bcmath', 'gmp', 'json', 'openssl', 'zip'];
        $result = ['status' => 'healthy', 'loaded' => [], 'missing' => []];
        foreach ($required as $ext) {
            if (extension_loaded($ext)) {
                $result['loaded'][] = $ext;
            } else {
                $result['missing'][] = $ext;
                $result['status'] = 'down';
            }
        }
        return $result;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return "$bytes B";
        if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return number_format($bytes / 1048576, 1) . ' MB';
        return number_format($bytes / 1073741824, 1) . ' GB';
    }
}

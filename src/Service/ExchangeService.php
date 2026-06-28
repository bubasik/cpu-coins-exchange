<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\Redis;
use App\Core\Config;
use App\Wallet\AdapterRegistry;
use App\Wallet\HdWallet;

/**
 * Instant Exchange service - swap YTN <-> SUGAR without order book.
 *
 * Rate is derived from a reference rate (cached) plus a swap fee spread.
 * Flow:
 *   1. createOrder(from, to, amount, payoutAddress) -> swap_order row with deposit_address
 *   2. DepositWatcher polls deposit_address via /balance
 *   3. On confirmation, WithdrawalDispenser pays out the converted amount
 */
final class ExchangeService
{
    public function createOrder(
        string $fromCoin,
        string $toCoin,
        string $fromAmount,
        string $payoutAddress
    ): array {
        $fromCoin = strtoupper($fromCoin);
        $toCoin = strtoupper($toCoin);
        $supportedCoins = ['YTN', 'SUGAR', 'ADVC'];
        if (!in_array($fromCoin, $supportedCoins)) throw new \InvalidArgumentException("Invalid from_coin: $fromCoin");
        if (!in_array($toCoin, $supportedCoins)) throw new \InvalidArgumentException("Invalid to_coin: $toCoin");
        if ($fromCoin === $toCoin) throw new \InvalidArgumentException("from_coin must differ from to_coin");

        $fromAdapter = AdapterRegistry::get($fromCoin);
        $toAdapter   = AdapterRegistry::get($toCoin);

        // Validate payout address
        if (!$toAdapter->hdWallet()->validateAddress($payoutAddress)) {
            throw new \InvalidArgumentException('Invalid payout address for ' . $toCoin);
        }

        // Validate amount
        $fromSat = HdWallet::coinToSat($fromAmount, $fromAdapter->decimals());
        $minSat = HdWallet::coinToSat(Config::get('SWAP_MIN_AMOUNT', '0.01'), $fromAdapter->decimals());
        if ($fromSat < $minSat) {
            throw new \InvalidArgumentException("Minimum amount is " . Config::get('SWAP_MIN_AMOUNT', '0.01') . " $fromCoin");
        }

        // Compute rate and to_amount
        $rate = $this->getCurrentRate($fromCoin, $toCoin);
        $feePercent = (float)Config::get('SWAP_FEE_PERCENT', '0.5');
        $toSat = (int) bcmul((string) bcmul((string)$fromSat, $rate, 12), (string)(1 - $feePercent / 100), 0);
        if ($toSat <= 0) throw new \InvalidArgumentException('Output amount too small');

        // Generate deposit address (HD next index, atomic)
        $depositIndex = $this->nextDepositIndex($fromCoin);
        $depositAddress = $fromAdapter->deriveDepositAddress($depositIndex);

        // Insert order
        $pdo = Database::pdo();
        $id = 'SW' . date('ymd') . strtoupper(bin2hex(random_bytes(5)));
        $stmt = $pdo->prepare('
            INSERT INTO swap_orders
                (ref, from_coin, to_coin, from_amount_sat, to_amount_sat, rate, fee_percent,
                 payout_address, deposit_address, deposit_index, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $id, $fromCoin, $toCoin,
            $fromSat, $toSat,
            $rate, $feePercent,
            $payoutAddress, $depositAddress, $depositIndex,
            'pending', time()
        ]);
        $rowId = (int)$pdo->lastInsertId();

        return [
            'id' => $rowId,
            'ref' => $id,
            'from_coin' => $fromCoin,
            'to_coin' => $toCoin,
            'from_amount' => HdWallet::satToCoin($fromSat, $fromAdapter->decimals()),
            'to_amount' => HdWallet::satToCoin($toSat, $toAdapter->decimals()),
            'rate' => $rate,
            'deposit_address' => $depositAddress,
            'payout_address' => $payoutAddress,
            'status' => 'pending',
        ];
    }

    public function getOrder(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM swap_orders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getOrderByRef(string $ref): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM swap_orders WHERE ref = ?');
        $stmt->execute([$ref]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listRecent(int $limit = 50): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM swap_orders ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get cached exchange rate (from_coin -> to_coin).
     * Reference rates are env-configurable; production should fetch from a real price
     * source and cache for SWAP_RATE_CACHE_TTL seconds.
     *
     * Supported pairs (and their inverses are computed automatically):
     *   YTN <-> SUGAR    (RATE_YTN_TO_SUGAR, default 100.0)
     *   YTN <-> ADVC     (RATE_YTN_TO_ADVC,  default 1.0)
     *   SUGAR <-> ADVC   (RATE_SUGAR_TO_ADVC, default 0.01)
     */
    public function getCurrentRate(string $fromCoin, string $toCoin): string
    {
        $fromCoin = strtoupper($fromCoin);
        $toCoin = strtoupper($toCoin);

        $cacheKey = "rate:$fromCoin:$toCoin";
        $cached = Redis::get($cacheKey);
        if ($cached !== null) return (string)$cached;

        // Reference rates from env (with sensible defaults)
        $ref = [
            'YTN-SUGAR'   => (float)Config::get('RATE_YTN_TO_SUGAR', '100.0'),
            'YTN-ADVC'    => (float)Config::get('RATE_YTN_TO_ADVC', '1.0'),
            'SUGAR-ADVC'  => (float)Config::get('RATE_SUGAR_TO_ADVC', '0.01'),
        ];

        // Try direct pair
        $directKey = "$fromCoin-$toCoin";
        $inverseKey = "$toCoin-$fromCoin";
        if (isset($ref[$directKey])) {
            $rate = $ref[$directKey];
        } elseif (isset($ref[$inverseKey])) {
            $rate = 1.0 / $ref[$inverseKey];
        } else {
            // Compute via YTN as bridge (e.g. SUGAR -> ADVC via SUGAR -> YTN -> ADVC)
            $toYtn = $this->rateToYtn($fromCoin, $ref);
            $fromYtnToTarget = $this->rateFromYtn($toCoin, $ref);
            if ($toYtn === null || $fromYtnToTarget === null) {
                throw new \InvalidArgumentException("Unsupported pair $fromCoin/$toCoin");
            }
            $rate = $toYtn * $fromYtnToTarget;
        }

        $ttl = (int)Config::get('SWAP_RATE_CACHE_TTL', '300');
        Redis::set($cacheKey, (string)$rate, $ttl);
        return (string)$rate;
    }

    private function rateToYtn(string $coin, array $ref): ?float
    {
        return match ($coin) {
            'YTN'   => 1.0,
            'SUGAR' => 1.0 / $ref['YTN-SUGAR'],
            'ADVC'  => 1.0 / $ref['YTN-ADVC'],
            default => null,
        };
    }

    private function rateFromYtn(string $coin, array $ref): ?float
    {
        return match ($coin) {
            'YTN'   => 1.0,
            'SUGAR' => $ref['YTN-SUGAR'],
            'ADVC'  => $ref['YTN-ADVC'],
            default => null,
        };
    }

    public function setRate(string $fromCoin, string $toCoin, float $rate): void
    {
        $fromCoin = strtoupper($fromCoin);
        $toCoin = strtoupper($toCoin);
        $cacheKey = "rate:$fromCoin:$toCoin";
        $ttl = (int)Config::get('SWAP_RATE_CACHE_TTL', '300');
        Redis::set($cacheKey, (string)$rate, $ttl);

        // Also set inverse
        $invKey = "rate:$toCoin:$fromCoin";
        Redis::set($invKey, (string)(1.0 / $rate), $ttl);
    }

    private function nextDepositIndex(string $coin): int
    {
        // Try Redis first (atomic)
        if (Redis::isAvailable()) {
            $key = "hd:index:$coin";
            $idx = (int)Redis::client()->incr($key);
        } else {
            // Fallback: read from DB and increment (not atomic, but OK for low traffic)
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT last_index FROM hot_wallets WHERE coin = ?');
            $stmt->execute([$coin]);
            $row = $stmt->fetch();
            $idx = $row ? (int)$row['last_index'] + 1 : 1;
        }

        // Persist to DB (atomic via UPSERT)
        $stmt = Database::pdo()->prepare('
            INSERT INTO hot_wallets (coin, last_index, updated_at) VALUES (?, ?, ?)
            ON CONFLICT(coin) DO UPDATE SET last_index = MAX(last_index, ?), updated_at = ?
        ');
        $stmt->execute([$coin, $idx, time(), $idx, time()]);
        return $idx;
    }
}

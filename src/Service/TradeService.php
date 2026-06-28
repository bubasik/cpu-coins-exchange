<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\Redis;
use App\Core\Config;
use App\Core\Auth;

/**
 * Trading service - manages limit/market orders for any configured pair.
 *
 * Pairs are configured via TRADE_PAIRS env var (e.g. "YTN/SUGAR,YTN/ADVC").
 * See App\Service\TradePairRegistry for details.
 *
 * For a pair BASE/QUOTE:
 *   BUY  BASE with QUOTE: pay QUOTE, receive BASE
 *   SELL BASE for QUOTE:  pay BASE,  receive QUOTE
 */
final class TradeService
{
    /**
     * Place a limit order. Locks the required balance.
     */
    public function placeLimitOrder(int $userId, string $pairKey, string $side, string $price, string $amount): array
    {
        $pair = TradePairRegistry::get($pairKey);
        if (!$pair) throw new \InvalidArgumentException("Unknown pair: $pairKey");

        $side = strtolower($side);
        if (!in_array($side, ['buy', 'sell'])) throw new \InvalidArgumentException('Invalid side');

        $priceSat = (int)bcmul($price, '100000000', 0);
        $amountSat = (int)bcmul($amount, '100000000', 0);
        if ($priceSat <= 0) throw new \InvalidArgumentException('Invalid price');
        if ($amountSat <= 0) throw new \InvalidArgumentException('Invalid amount');

        $minAmt = $pair->minAmount;
        if ((float)$amount < $minAmt) {
            throw new \InvalidArgumentException("Min amount: $minAmt");
        }

        // Lock balance
        // costSat = price_per_coin (sats) * amount_in_coins = priceSat * amountSat / 1e8
        if ($side === 'buy') {
            $costSat = (int)bcdiv(bcmul((string)$priceSat, (string)$amountSat, 0), '100000000', 0);
            $this->lockBalance($userId, $pair->quote, $costSat);
        } else {
            $this->lockBalance($userId, $pair->base, $amountSat);
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('
            INSERT INTO orders (user_id, pair, side, type, price_sat, amount_sat, filled_sat, status, created_at)
            VALUES (?, ?, ?, "limit", ?, ?, 0, "open", ?)
        ');
        $stmt->execute([
            $userId, $pair->key, $side, $priceSat, $amountSat, time()
        ]);
        $orderId = (int)$pdo->lastInsertId();

        $this->publishOrder($orderId, $pair->key, $side, $priceSat, $amountSat);

        return ['order_id' => $orderId, 'status' => 'open'];
    }

    public function placeMarketOrder(int $userId, string $pairKey, string $side, string $amount, bool $amountIsQuote = false): array
    {
        $pair = TradePairRegistry::get($pairKey);
        if (!$pair) throw new \InvalidArgumentException("Unknown pair: $pairKey");

        $side = strtolower($side);
        if (!in_array($side, ['buy', 'sell'])) throw new \InvalidArgumentException('Invalid side');

        $amountSat = (int)bcmul($amount, '100000000', 0);
        if ($amountSat <= 0) throw new \InvalidArgumentException('Invalid amount');

        if ($side === 'buy' && !$amountIsQuote) {
            throw new \InvalidArgumentException('Market BUY must specify quote amount');
        }
        if ($side === 'sell' && $amountIsQuote) {
            throw new \InvalidArgumentException('Market SELL must specify base amount');
        }

        if ($side === 'buy') {
            $this->lockBalance($userId, $pair->quote, $amountSat);
        } else {
            $this->lockBalance($userId, $pair->base, $amountSat);
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('
            INSERT INTO orders (user_id, pair, side, type, price_sat, amount_sat, filled_sat, status, created_at)
            VALUES (?, ?, ?, "market", 0, ?, 0, "matching", ?)
        ');
        $stmt->execute([
            $userId, $pair->key, $side, $amountSat, time()
        ]);
        $orderId = (int)$pdo->lastInsertId();

        return ['order_id' => $orderId, 'status' => 'matching'];
    }

    public function cancelOrder(int $userId, int $orderId): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ?');
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch();
        if (!$order || $order['status'] !== 'open') return false;

        $pair = TradePairRegistry::get($order['pair']);
        if (!$pair) return false;

        $remaining = (int)$order['amount_sat'] - (int)$order['filled_sat'];
        if ($remaining <= 0) return false;

        if ($order['side'] === 'buy') {
            $cost = (int)bcdiv(bcmul((string)$remaining, (string)$order['price_sat'], 0), '100000000', 0);
            $this->unlockBalance($userId, $pair->quote, $cost);
        } else {
            $this->unlockBalance($userId, $pair->base, $remaining);
        }

        $stmt = $pdo->prepare('UPDATE orders SET status = "cancelled" WHERE id = ?');
        $stmt->execute([$orderId]);

        $this->removeOrderFromBook($orderId, $pair->key, $order['side'], (int)$order['price_sat']);
        return true;
    }

    /**
     * Get order book for a pair. Falls back to SQLite query when Redis unavailable.
     */
    public function getOrderBook(string $pairKey = null, int $depth = 50): array
    {
        $pair = $pairKey ? TradePairRegistry::get($pairKey) : TradePairRegistry::default();
        if (!$pair) throw new \InvalidArgumentException("Unknown pair: $pairKey");

        if (Redis::isAvailable()) {
            $redis = Redis::client();
            $bidsKey = "book:bids:{$pair->key}";
            $asksKey = "book:asks:{$pair->key}";
            $bids = $redis->zrevrange($bidsKey, 0, $depth - 1, ['withscores' => true]);
            $asks = $redis->zrange($asksKey, 0, $depth - 1, ['withscores' => true]);

            $bidList = [];
            foreach ($bids as $orderId => $price) {
                $size = (int)$redis->hget("book:orders:{$pair->key}", (string)$orderId);
                if ($size > 0) {
                    $bidList[] = ['price' => (int)$price, 'amount' => $size];
                }
            }
            $askList = [];
            foreach ($asks as $orderId => $price) {
                $size = (int)$redis->hget("book:orders:{$pair->key}", (string)$orderId);
                if ($size > 0) {
                    $askList[] = ['price' => (int)$price, 'amount' => $size];
                }
            }
            return ['bids' => $bidList, 'asks' => $askList];
        }

        // Fallback: query SQLite directly (filtered by pair)
        $pdo = Database::pdo();
        $bidsStmt = $pdo->prepare('
            SELECT price_sat, SUM(amount_sat - filled_sat) AS amount
            FROM orders WHERE pair = ? AND side = "buy" AND status = "open" AND type = "limit"
            GROUP BY price_sat ORDER BY price_sat DESC LIMIT ?
        ');
        $bidsStmt->execute([$pair->key, $depth]);
        $bidList = array_map(fn($r) => ['price' => (int)$r['price_sat'], 'amount' => (int)$r['amount']], $bidsStmt->fetchAll());

        $asksStmt = $pdo->prepare('
            SELECT price_sat, SUM(amount_sat - filled_sat) AS amount
            FROM orders WHERE pair = ? AND side = "sell" AND status = "open" AND type = "limit"
            GROUP BY price_sat ORDER BY price_sat ASC LIMIT ?
        ');
        $asksStmt->execute([$pair->key, $depth]);
        $askList = array_map(fn($r) => ['price' => (int)$r['price_sat'], 'amount' => (int)$r['amount']], $asksStmt->fetchAll());

        return ['bids' => $bidList, 'asks' => $askList];
    }

    public function getRecentTrades(int $limit = 50, string $pairKey = null): array
    {
        $pdo = Database::pdo();
        if ($pairKey) {
            $stmt = $pdo->prepare('
                SELECT t.*, o.side, o.pair FROM trades t
                LEFT JOIN orders o ON o.id = t.taker_order_id
                WHERE o.pair = ?
                ORDER BY t.id DESC LIMIT ?
            ');
            $stmt->bindValue(1, $pairKey, \PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        } else {
            $stmt = $pdo->prepare('
                SELECT t.*, o.side, o.pair FROM trades t
                LEFT JOIN orders o ON o.id = t.taker_order_id
                ORDER BY t.id DESC LIMIT ?
            ');
            $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getCandles(string $interval = '1m', int $limit = 200, string $pairKey = null): array
    {
        $pdo = Database::pdo();
        if ($pairKey) {
            $stmt = $pdo->prepare("
                SELECT
                    FLOOR(t.created_at / 60) * 60 AS ts,
                    (SELECT price_sat FROM trades WHERE id = MIN(t.id)) AS open,
                    MAX(t.price_sat) AS high,
                    MIN(t.price_sat) AS low,
                    (SELECT price_sat FROM trades WHERE id = MAX(t.id)) AS close,
                    SUM(t.amount_sat) AS volume
                FROM trades t
                JOIN orders o ON o.id = t.taker_order_id
                WHERE o.pair = ?
                GROUP BY FLOOR(t.created_at / 60) * 60
                ORDER BY ts DESC LIMIT ?
            ");
            $stmt->bindValue(1, $pairKey, \PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        } else {
            $stmt = $pdo->prepare('
                SELECT ts, open, high, low, close, volume FROM candles
                WHERE interval = ?
                ORDER BY ts DESC LIMIT ?
            ');
            $stmt->bindValue(1, $interval, \PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = array_reverse($stmt->fetchAll());
        return $rows;
    }

    public function listUserOrders(int $userId, int $limit = 50, string $pairKey = null): array
    {
        $pdo = Database::pdo();
        if ($pairKey) {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE user_id = ? AND pair = ? ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
            $stmt->bindValue(2, $pairKey, \PDO::PARAM_STR);
            $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function listUserTrades(int $userId, int $limit = 50, string $pairKey = null): array
    {
        $pdo = Database::pdo();
        if ($pairKey) {
            $stmt = $pdo->prepare('
                SELECT t.*, o.pair FROM trades t
                JOIN orders o ON o.id = t.taker_order_id
                WHERE (t.taker_order_id IN (SELECT id FROM orders WHERE user_id = ?)
                       OR t.maker_order_id IN (SELECT id FROM orders WHERE user_id = ?))
                  AND o.pair = ?
                ORDER BY t.id DESC LIMIT ?
            ');
            $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
            $stmt->bindValue(2, $userId, \PDO::PARAM_INT);
            $stmt->bindValue(3, $pairKey, \PDO::PARAM_STR);
            $stmt->bindValue(4, $limit, \PDO::PARAM_INT);
        } else {
            $stmt = $pdo->prepare('
                SELECT t.*, o.pair FROM trades t
                LEFT JOIN orders o ON o.id = t.taker_order_id
                WHERE t.taker_order_id IN (SELECT id FROM orders WHERE user_id = ?)
                   OR t.maker_order_id IN (SELECT id FROM orders WHERE user_id = ?)
                ORDER BY t.id DESC LIMIT ?
            ');
            $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
            $stmt->bindValue(2, $userId, \PDO::PARAM_INT);
            $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function lockBalance(int $userId, string $coin, int $amountSat): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                UPDATE user_wallets
                SET balance = balance - ?, locked = locked + ?
                WHERE user_id = ? AND coin = ? AND balance >= ?
            ');
            $stmt->execute([$amountSat, $amountSat, $userId, $coin, $amountSat]);
            if ($stmt->rowCount() === 0) {
                throw new \RuntimeException("Insufficient $coin balance");
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function unlockBalance(int $userId, string $coin, int $amountSat): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('
            UPDATE user_wallets
            SET balance = balance + ?, locked = locked - ?
            WHERE user_id = ? AND coin = ?
        ');
        $stmt->execute([$amountSat, $amountSat, $userId, $coin]);
    }

    private function publishOrder(int $orderId, string $pairKey, string $side, int $priceSat, int $amountSat): void
    {
        if (!Redis::isAvailable()) return;
        $redis = Redis::client();
        $key = $side === 'buy' ? "book:bids:$pairKey" : "book:asks:$pairKey";
        $redis->zadd($key, [$orderId => $priceSat]);
        $redis->hset("book:orders:$pairKey", (string)$orderId, (string)$amountSat);
    }

    private function removeOrderFromBook(int $orderId, string $pairKey, string $side, int $priceSat): void
    {
        if (!Redis::isAvailable()) return;
        $redis = Redis::client();
        $key = $side === 'buy' ? "book:bids:$pairKey" : "book:asks:$pairKey";
        $redis->zrem($key, (string)$orderId);
        $redis->hdel("book:orders:$pairKey", [(string)$orderId]);
    }
}

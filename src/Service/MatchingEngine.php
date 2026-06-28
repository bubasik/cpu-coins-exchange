<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\Redis;
use App\Core\Config;

/**
 * Matching engine - runs in worker process, scans open orders and matches them.
 *
 * Algorithm:
 *   1. Pick the oldest open order (FIFO).
 *   2. Look at the opposite side of the order book at acceptable prices.
 *   3. Match as much as possible. Each fill creates a Trade row, updates both orders,
 *      moves balances between users.
 *   4. Repeat until no more matches possible.
 *
 * Price priority: best bid (highest) vs best ask (lowest).
 * Time priority: FIFO within same price.
 */
final class MatchingEngine
{
    private const FEE_PERCENT = 0.2;

    public function tick(): int
    {
        $matched = 0;

        // Iterate over each configured pair and match within it
        foreach (\App\Service\TradePairRegistry::listKeys() as $pairKey) {
            $matched += $this->tickPair($pairKey);
        }

        // Process market orders across all pairs
        $this->matchMarketOrders();

        return $matched;
    }

    /**
     * Match open limit orders for a single pair.
     */
    private function tickPair(string $pairKey): int
    {
        $matched = 0;
        $pdo = Database::pdo();

        // Loop until no more matches
        while (true) {
            // Find best bid (highest price open buy) and best ask (lowest price open sell) for THIS pair
            $stmt = $pdo->prepare('
                SELECT * FROM orders
                WHERE pair = ? AND side = "buy" AND status = "open" AND type = "limit"
                ORDER BY price_sat DESC, id ASC LIMIT 1
            ');
            $stmt->execute([$pairKey]);
            $bestBid = $stmt->fetch();

            $stmt = $pdo->prepare('
                SELECT * FROM orders
                WHERE pair = ? AND side = "sell" AND status = "open" AND type = "limit"
                ORDER BY price_sat ASC, id ASC LIMIT 1
            ');
            $stmt->execute([$pairKey]);
            $bestAsk = $stmt->fetch();

            if (!$bestBid || !$bestAsk) break;
            if ((int)$bestBid['price_sat'] < (int)$bestAsk['price_sat']) break;

            // Match!
            $matchPrice = (int)$bestAsk['price_sat'];
            $bidRemaining = (int)$bestBid['amount_sat'] - (int)$bestBid['filled_sat'];
            $askRemaining = (int)$bestAsk['amount_sat'] - (int)$bestAsk['filled_sat'];
            $matchAmount = min($bidRemaining, $askRemaining);
            if ($matchAmount <= 0) break;

            $matched++;
            $this->executeFill(
                (int)$bestBid['id'], (int)$bestBid['user_id'],
                (int)$bestAsk['id'], (int)$bestAsk['user_id'],
                $matchPrice, $matchAmount
            );
        }

        return $matched;
    }

    private function executeFill(
        int $bidOrderId, int $bidUserId,
        int $askOrderId, int $askUserId,
        int $priceSat, int $amountSat
    ): void {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $costSat = (int)bcdiv(bcmul((string)$priceSat, (string)$amountSat, 0), '100000000', 0);

            // Fee (0.2% default, configurable)
            $feePercent = (float)Config::get('TRADE_FEE_PERCENT', '0.2');
            $feeRate = bcdiv((string)$feePercent, '100', 8);
            $baseFee = (int)bcmul((string)$amountSat, $feeRate, 0);
            $quoteFee = (int)bcmul((string)$costSat, $feeRate, 0);

            // Buyer pays quote (SUGAR) from locked, receives base (YTN) - fee
            // Seller pays base (YTN) from locked, receives quote (SUGAR) - fee

            // Update buyer: locked SUGAR down by cost, balance YTN up by amount - fee
            $this->updateWallet($bidUserId, 'SUGAR', -1 * $costSat, -1 * $costSat); // balance delta, locked delta
            $this->creditWallet($bidUserId, 'YTN', $amountSat - $baseFee);

            // Update seller: locked YTN down by amount, balance SUGAR up by cost - fee
            $this->updateWallet($askUserId, 'YTN', -1 * $amountSat, -1 * $amountSat);
            $this->creditWallet($askUserId, 'SUGAR', $costSat - $quoteFee);

            // Update orders: filled++
            $pdo->prepare('UPDATE orders SET filled_sat = filled_sat + ? WHERE id = ?')
                ->execute([$amountSat, $bidOrderId]);
            $pdo->prepare('UPDATE orders SET filled_sat = filled_sat + ? WHERE id = ?')
                ->execute([$amountSat, $askOrderId]);

            // Check if fully filled
            $this->maybeCompleteOrder($bidOrderId);
            $this->maybeCompleteOrder($askOrderId);

            // Insert trade
            $pdo->prepare('
                INSERT INTO trades (maker_order_id, taker_order_id, price_sat, amount_sat, side, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ')->execute([$askOrderId, $bidOrderId, $priceSat, $amountSat, 'buy', time()]);

            $tradeId = (int)$pdo->lastInsertId();

            // Update Redis order book (reduce remaining size)
            $this->updateBookAfterFill($bidOrderId, 'buy', $amountSat);
            $this->updateBookAfterFill($askOrderId, 'sell', $amountSat);

            // Publish trade to Redis for realtime feed
            Redis::set('trade:last', [
                'id' => $tradeId,
                'price' => $priceSat,
                'amount' => $amountSat,
                'side' => 'buy',
                'ts' => time(),
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('MatchingEngine fill failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function matchMarketOrders(): void
    {
        $pdo = Database::pdo();
        // Find market orders in 'matching' status
        $stmt = $pdo->query('SELECT * FROM orders WHERE type = "market" AND status = "matching" ORDER BY id ASC');
        $marketOrders = $stmt->fetchAll();
        foreach ($marketOrders as $mo) {
            $this->executeMarketFill($mo);
        }
    }

    private function executeMarketFill(array $marketOrder): void
    {
        $pdo = Database::pdo();
        $orderId = (int)$marketOrder['id'];
        $userId = (int)$marketOrder['user_id'];
        $side = $marketOrder['side'];

        // Total to spend (buy: quote SUGAR, sell: base YTN)
        $remaining = (int)$marketOrder['amount_sat'] - (int)$marketOrder['filled_sat'];
        if ($remaining <= 0) {
            $pdo->prepare('UPDATE orders SET status = "filled" WHERE id = ?')->execute([$orderId]);
            return;
        }

        $opposite = $side === 'buy' ? 'sell' : 'buy';
        // Get opposite-side limit orders ordered by best price (for buy: asc, for sell: desc)
        $orderDir = $side === 'buy' ? 'ASC' : 'DESC';
        $stmt = $pdo->prepare("
            SELECT * FROM orders
            WHERE side = ? AND status = 'open' AND type = 'limit'
            ORDER BY price_sat $orderDir, id ASC
        ");
        $stmt->execute([$opposite]);
        $makers = $stmt->fetchAll();

        foreach ($makers as $maker) {
            if ($remaining <= 0) break;
            $makerRemaining = (int)$maker['amount_sat'] - (int)$maker['filled_sat'];
            if ($makerRemaining <= 0) continue;

            $matchPrice = (int)$maker['price_sat'];

            if ($side === 'buy') {
                // Buyer has SUGAR to spend; compute how much base (sats) they can buy at this price
                // remaining is in quote sats; matchPrice is sats per base coin;
                // canAfford (in base sats) = remaining * 1e8 / matchPrice
                $canAfford = (int)bcdiv(bcmul((string)$remaining, '100000000', 0), (string)$matchPrice, 0);
                $matchAmount = min($canAfford, $makerRemaining);
                if ($matchAmount <= 0) break;
                $cost = (int)bcdiv(bcmul((string)$matchPrice, (string)$matchAmount, 0), '100000000', 0);
                $remaining -= $cost;
            } else {
                // Seller has YTN to sell
                $matchAmount = min($remaining, $makerRemaining);
                $remaining -= $matchAmount;
            }

            $takerId = $orderId;
            $makerId = (int)$maker['id'];
            $takerUserId = $userId;
            $makerUserId = (int)$maker['user_id'];

            if ($side === 'buy') {
                $this->executeFill($takerId, $takerUserId, $makerId, $makerUserId, $matchPrice, $matchAmount);
            } else {
                $this->executeFill($makerId, $makerUserId, $takerId, $takerUserId, $matchPrice, $matchAmount);
            }
        }

        // Update market order filled and status
        $pdo->prepare('UPDATE orders SET filled_sat = amount_sat - ?, status = ? WHERE id = ?')
            ->execute([$remaining, $remaining <= 0 ? 'filled' : 'partial', $orderId]);

        // If market buy couldn't fully fill, refund remaining quote balance
        if ($remaining > 0 && $side === 'buy') {
            $this->creditWallet($userId, 'SUGAR', $remaining);
        }
        if ($remaining > 0 && $side === 'sell') {
            $this->creditWallet($userId, 'YTN', $remaining);
        }
    }

    private function maybeCompleteOrder(int $orderId): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT amount_sat, filled_sat, type, side, user_id FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $o = $stmt->fetch();
        if (!$o) return;
        if ((int)$o['filled_sat'] >= (int)$o['amount_sat']) {
            $pdo->prepare('UPDATE orders SET status = "filled" WHERE id = ?')->execute([$orderId]);
        }
    }

    private function updateWallet(int $userId, string $coin, int $balanceDelta, int $lockedDelta): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('
            UPDATE user_wallets SET balance = balance + ?, locked = locked + ?
            WHERE user_id = ? AND coin = ?
        ')->execute([$balanceDelta, $lockedDelta, $userId, $coin]);
    }

    private function creditWallet(int $userId, string $coin, int $amountSat): void
    {
        $pdo = Database::pdo();
        // Insert wallet row if not exists, then update
        $pdo->prepare('INSERT OR IGNORE INTO user_wallets (user_id, coin, balance, locked) VALUES (?, ?, 0, 0)')
            ->execute([$userId, $coin]);
        $pdo->prepare('UPDATE user_wallets SET balance = balance + ? WHERE user_id = ? AND coin = ?')
            ->execute([$amountSat, $userId, $coin]);
    }

    private function updateBookAfterFill(int $orderId, string $side, int $filledAmount): void
    {
        if (!Redis::isAvailable()) return;
        $redis = Redis::client();
        $current = (int)$redis->hget('book:orders', (string)$orderId);
        $newSize = $current - $filledAmount;
        if ($newSize <= 0) {
            $key = $side === 'buy' ? 'book:bids' : 'book:asks';
            $redis->zrem($key, (string)$orderId);
            $redis->hdel('book:orders', [(string)$orderId]);
        } else {
            $redis->hset('book:orders', (string)$orderId, (string)$newSize);
        }
    }

    /**
     * Aggregate recent trades into 1-minute candles. Called by cron every minute.
     */
    public function aggregateCandles(): void
    {
        $pdo = Database::pdo();
        $lastTs = (int)$pdo->query("SELECT COALESCE(MAX(ts), 0) FROM candles WHERE interval = '1m'")->fetchColumn();

        // Include trades from lastTs onwards (inclusive, since a trade could be in same minute as last candle)
        $stmt = $pdo->prepare('
            SELECT * FROM trades WHERE created_at >= ?
            ORDER BY id ASC
        ');
        $stmt->execute([$lastTs]);
        $trades = $stmt->fetchAll();

        $buckets = [];
        foreach ($trades as $t) {
            $bucket = (int)(floor((int)$t['created_at'] / 60) * 60);
            if (!isset($buckets[$bucket])) {
                $buckets[$bucket] = [
                    'open' => (int)$t['price_sat'],
                    'high' => (int)$t['price_sat'],
                    'low' => (int)$t['price_sat'],
                    'close' => (int)$t['price_sat'],
                    'volume' => 0,
                ];
            }
            $buckets[$bucket]['high'] = max($buckets[$bucket]['high'], (int)$t['price_sat']);
            $buckets[$bucket]['low'] = min($buckets[$bucket]['low'], (int)$t['price_sat']);
            $buckets[$bucket]['close'] = (int)$t['price_sat'];
            $buckets[$bucket]['volume'] += (int)$t['amount_sat'];
        }

        foreach ($buckets as $ts => $c) {
            $pdo->prepare('
                INSERT OR REPLACE INTO candles (interval, ts, open, high, low, close, volume)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ')->execute(['1m', $ts, $c['open'], $c['high'], $c['low'], $c['close'], $c['volume']]);
        }
    }
}

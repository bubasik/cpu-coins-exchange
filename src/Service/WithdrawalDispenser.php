<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Wallet\AdapterRegistry;

/**
 * Withdrawal dispenser - processes confirmed swap orders and pending user withdrawals.
 *
 * For each item:
 *   1. Get fee rate via API /fee
 *   2. Build + sign raw tx via bitcoin-php (uses xprv - WORKER PROCESS ONLY)
 *   3. Verify by decoding via API /decode
 *   4. Broadcast via API POST /broadcast
 *   5. Save txid, mark as sent
 *
 * Idempotency: status moves 'confirmed' -> 'sending' -> 'sent' atomically.
 */
final class WithdrawalDispenser
{
    public function tick(): int
    {
        $processed = 0;
        $pdo = Database::pdo();

        // ===== Swap payouts =====
        $stmt = $pdo->query('SELECT * FROM swap_orders WHERE status = "confirmed" AND payout_txid IS NULL');
        $swaps = $stmt->fetchAll();
        foreach ($swaps as $swap) {
            // Lock row to avoid concurrent processing
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('UPDATE swap_orders SET status = "sending" WHERE id = ? AND status = "confirmed"');
                $stmt->execute([$swap['id']]);
                if ($stmt->rowCount() === 0) {
                    $pdo->rollBack();
                    continue;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                continue;
            }

            try {
                $adapter = AdapterRegistry::get($swap['to_coin']);
                $feeRate = $this->getFeeRate($adapter);
                echo "[swap #{$swap['id']}] Building payout tx: {$swap['to_amount_sat']} sats to {$swap['payout_address']} (fee=$feeRate sat/vB)\n";
                $hex = $adapter->buildPayoutTx(
                    $swap['payout_address'],
                    (int)$swap['to_amount_sat'],
                    $feeRate
                );
                echo "[swap #{$swap['id']}] Tx built (" . strlen($hex) . " hex chars), decoding...\n";

                // Sanity check: decode
                $decoded = $adapter->api()->decodeRaw($hex);
                echo "[swap #{$swap['id']}] Decoded OK, broadcasting...\n";

                // Broadcast
                $txid = $adapter->broadcast($hex);
                echo "[swap #{$swap['id']}] Broadcast OK, txid=$txid\n";

                $pdo->prepare('
                    UPDATE swap_orders
                    SET status = "sent", payout_txid = ?, payout_fee_rate = ?, sent_at = ?
                    WHERE id = ?
                ')->execute([$txid, $feeRate, time(), $swap['id']]);

                $processed++;
            } catch (\Throwable $e) {
                error_log("Dispenser swap #{$swap['id']} error: " . $e->getMessage());
                $pdo->prepare('UPDATE swap_orders SET status = "confirmed", error = ? WHERE id = ?')
                    ->execute([$e->getMessage(), $swap['id']]);
            }
        }

        // Check sent swaps for confirmation (mark completed)
        $stmt = $pdo->query('SELECT * FROM swap_orders WHERE status = "sent" AND payout_txid IS NOT NULL');
        foreach ($stmt->fetchAll() as $swap) {
            try {
                $adapter = AdapterRegistry::get($swap['to_coin']);
                $tx = $adapter->api()->getTransaction($swap['payout_txid']);
                $txHeight = (int)($tx['height'] ?? 0);
                $conf = $adapter->getConfirmations($txHeight);
                if ($conf >= $adapter->confirmationsRequired()) {
                    $pdo->prepare('UPDATE swap_orders SET status = "completed", completed_at = ? WHERE id = ?')
                        ->execute([time(), $swap['id']]);
                    $processed++;
                }
            } catch (\Throwable $e) {
                // ignore: tx may not yet be visible in explorer
            }
        }

        // ===== User withdrawals =====
        $stmt = $pdo->query('SELECT * FROM withdrawals WHERE status = "pending"');
        $withdrawals = $stmt->fetchAll();
        foreach ($withdrawals as $w) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('UPDATE withdrawals SET status = "sending" WHERE id = ? AND status = "pending"');
                $stmt->execute([$w['id']]);
                if ($stmt->rowCount() === 0) {
                    $pdo->rollBack();
                    continue;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                continue;
            }

            try {
                $adapter = AdapterRegistry::get($w['coin']);
                $feeRate = $this->getFeeRate($adapter);
                $hex = $adapter->buildPayoutTx(
                    $w['address'],
                    (int)$w['amount_sat'],
                    $feeRate
                );
                $adapter->api()->decodeRaw($hex); // sanity
                $txid = $adapter->broadcast($hex);

                $pdo->prepare('
                    UPDATE withdrawals SET status = "sent", txid = ?, fee_rate = ?, sent_at = ?
                    WHERE id = ?
                ')->execute([$txid, $feeRate, time(), $w['id']]);
                $processed++;
            } catch (\Throwable $e) {
                error_log("Dispenser withdrawal #{$w['id']} error: " . $e->getMessage());
                $pdo->prepare('UPDATE withdrawals SET status = "pending", error = ? WHERE id = ?')
                    ->execute([$e->getMessage(), $w['id']]);
            }
        }

        // Check sent withdrawals for confirmation
        $stmt = $pdo->query('SELECT * FROM withdrawals WHERE status = "sent" AND txid IS NOT NULL');
        foreach ($stmt->fetchAll() as $w) {
            try {
                $adapter = AdapterRegistry::get($w['coin']);
                $tx = $adapter->api()->getTransaction($w['txid']);
                $txHeight = (int)($tx['height'] ?? 0);
                $conf = $adapter->getConfirmations($txHeight);
                if ($conf >= $adapter->confirmationsRequired()) {
                    $pdo->prepare('UPDATE withdrawals SET status = "completed", completed_at = ? WHERE id = ?')
                        ->execute([time(), $w['id']]);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $processed;
    }

    private function getFeeRate($adapter): int
    {
        try {
            $fee = $adapter->api()->getFee();
            $rate = (int)($fee['feerate'] ?? 1000);
            // Cap fee rate — some APIs return absurdly high values (e.g. 1M sat/vB)
            // which would result in "absurdly-high-fee" rejection.
            // Max 100 sat/vB = 0.000001 coin/vB (reasonable for these low-traffic coins)
            return min($rate, 100);
        } catch (\Throwable $e) {
            return 10; // default 10 sat/vbyte
        }
    }
}

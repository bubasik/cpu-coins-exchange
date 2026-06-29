<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Wallet\AdapterRegistry;

/**
 * Watches deposit addresses for incoming transactions and credits
 * the relevant destination (swap order payout OR user wallet balance).
 *
 * Runs in worker process, polls every 30 seconds.
 *
 * Two types of deposits:
 *   1. swap_orders.deposit_address    -> on confirmation triggers payout to user
 *   2. user deposit addresses         -> on confirmation credits user_wallets
 */
final class DepositWatcher
{
    public function tick(): int
    {
        $processed = 0;
        $pdo = Database::pdo();

        // ===== Swap deposits =====
        // Select ALL pending swaps (not just those without deposit_txid).
        // Once a deposit is detected, deposit_txid is set, but we still need to
        // keep checking confirmations until they reach the required threshold.
        $stmt = $pdo->query('
            SELECT * FROM swap_orders
            WHERE status = "pending"
            ORDER BY id ASC
        ');
        $pendingSwaps = $stmt->fetchAll();

        $count = count($pendingSwaps);
        if ($count > 0) {
            echo "[" . date('c') . "] DepositWatcher: checking $count pending swap(s)\n";
        }

        foreach ($pendingSwaps as $swap) {
            $swapId = $swap['id'];
            $ref = $swap['ref'] ?? '?';
            $fromCoin = $swap['from_coin'];
            $addr = trim($swap['deposit_address']);

            try {
                $adapter = AdapterRegistry::get($fromCoin);

                // If deposit_txid is already set, skip balance/history check
                // and just re-check confirmations for the known txid.
                if (!empty($swap['deposit_txid'])) {
                    $txid = $swap['deposit_txid'];
                    echo "  [swap #$swapId $ref] $fromCoin — re-checking tx $txid\n";

                    $tx = $adapter->api()->getTransaction($txid);
                    $txHeight = (int)($tx['height'] ?? 0);
                    $confirmations = $adapter->getConfirmations($txHeight);
                    $required = $adapter->confirmationsRequired();
                    echo "  [swap #$swapId] confirmations=$confirmations/$required\n";

                    // Update confirmations in DB
                    $pdo->prepare('UPDATE swap_orders SET deposit_height = ?, confirmations = ? WHERE id = ?')
                        ->execute([$txHeight, $confirmations, $swapId]);

                    if ($confirmations >= $required) {
                        $pdo->prepare('UPDATE swap_orders SET status = "confirmed", confirmed_at = ? WHERE id = ?')
                            ->execute([time(), $swapId]);
                        echo "  [swap #$swapId] ✅ status → confirmed\n";
                    } else {
                        echo "  [swap #$swapId] ⏳ waiting for $required confirmations (have $confirmations)\n";
                    }
                    $processed++;
                    continue;
                }

                // No deposit_txid yet — check for incoming deposit
                echo "  [swap #$swapId $ref] $fromCoin addr=$addr — checking balance...\n";

                $bal = $adapter->getBalanceSat($addr);
                echo "  [swap #$swapId] balance=$bal sats\n";

                if ($bal <= 0) {
                    echo "  [swap #$swapId] no deposit yet, skipping\n";
                    continue;
                }

                // Find the incoming tx
                $history = $adapter->api()->getHistory($addr);
                $txIds = $history['tx'] ?? [];
                if (empty($txIds)) {
                    echo "  [swap #$swapId] balance>0 but no txs in history, skipping\n";
                    continue;
                }
                $txid = $txIds[0];
                echo "  [swap #$swapId] found tx: $txid\n";

                // Verify tx and find vout to our address
                $tx = $adapter->api()->getTransaction($txid);
                $txHeight = (int)($tx['height'] ?? 0);
                $confirmations = $adapter->getConfirmations($txHeight);
                $required = $adapter->confirmationsRequired();
                echo "  [swap #$swapId] height=$txHeight, confirmations=$confirmations/$required\n";

                // Save deposit_txid immediately
                $pdo->prepare('UPDATE swap_orders SET deposit_txid = ?, deposit_height = ?, confirmations = ?, deposit_address = ? WHERE id = ?')
                    ->execute([$txid, $txHeight, $confirmations, $addr, $swapId]);

                if ($confirmations >= $required) {
                    $pdo->prepare('UPDATE swap_orders SET status = "confirmed", confirmed_at = ? WHERE id = ?')
                        ->execute([time(), $swapId]);
                    echo "  [swap #$swapId] ✅ status → confirmed\n";
                } else {
                    echo "  [swap #$swapId] ⏳ waiting for $required confirmations (have $confirmations)\n";
                }
                $processed++;
            } catch (\Throwable $e) {
                echo "  [swap #$swapId] ❌ ERROR: " . $e->getMessage() . "\n";
                error_log("DepositWatcher swap #$swapId error: " . $e->getMessage());
            }
        }

        // Check confirmed swaps awaiting payout status update
        $stmt = $pdo->query('SELECT * FROM swap_orders WHERE status = "confirmed" AND deposit_txid IS NOT NULL');
        foreach ($stmt->fetchAll() as $swap) {
            try {
                $adapter = AdapterRegistry::get($swap['from_coin']);
                $tx = $adapter->api()->getTransaction($swap['deposit_txid']);
                $txHeight = (int)($tx['height'] ?? 0);
                $conf = $adapter->getConfirmations($txHeight);
                $pdo->prepare('UPDATE swap_orders SET confirmations = ? WHERE id = ?')
                    ->execute([$conf, $swap['id']]);
            } catch (\Throwable $e) {
                error_log("DepositWatcher confirm-check swap #{$swap['id']} error: " . $e->getMessage());
            }
        }

        // ===== User wallet deposits =====
        $stmt = $pdo->query('SELECT * FROM deposits WHERE status = "pending"');
        $pendingDeposits = $stmt->fetchAll();
        foreach ($pendingDeposits as $dep) {
            try {
                $adapter = AdapterRegistry::get($dep['coin']);
                $bal = $adapter->getBalanceSat($dep['address']);
                if ($bal <= 0) continue;

                $history = $adapter->api()->getHistory($dep['address']);
                $txIds = $history['tx'] ?? [];
                if (empty($txIds)) continue;
                $txid = $txIds[0];

                $tx = $adapter->api()->getTransaction($txid);
                $txHeight = (int)($tx['height'] ?? 0);
                $conf = $adapter->getConfirmations($txHeight);
                $required = $adapter->confirmationsRequired();

                // Find amount to this address in this tx
                $amountSat = 0;
                foreach ($tx['vout'] ?? [] as $vout) {
                    $addrs = $vout['scriptPubKey']['addresses'] ?? [];
                    if (in_array($dep['address'], $addrs)) {
                        $amountSat += (int)$vout['value'];
                    }
                }

                $pdo->prepare('
                    UPDATE deposits SET txid = ?, height = ?, amount_sat = ?, confirmations = ?
                    WHERE id = ?
                ')->execute([$txid, $txHeight, $amountSat, $conf, $dep['id']]);

                if ($conf >= $required) {
                    // Credit user wallet
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('
                            INSERT OR IGNORE INTO user_wallets (user_id, coin, balance, locked)
                            VALUES (?, ?, 0, 0)
                        ')->execute([$dep['user_id'], $dep['coin']]);
                        $pdo->prepare('UPDATE user_wallets SET balance = balance + ? WHERE user_id = ? AND coin = ?')
                            ->execute([$amountSat, $dep['user_id'], $dep['coin']]);
                        $pdo->prepare('UPDATE deposits SET status = "confirmed", confirmed_at = ? WHERE id = ?')
                            ->execute([time(), $dep['id']]);
                        $pdo->commit();
                        $processed++;
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                }
            } catch (\Throwable $e) {
                error_log("DepositWatcher deposit #{$dep['id']} error: " . $e->getMessage());
            }
        }

        // ===== Cleanup expired pending swaps =====
        // Delete pending swaps older than SWAP_EXPIRY_HOURS (default 4 hours)
        // where no deposit was received (deposit_txid IS NULL).
        $expiryHours = (int)\App\Core\Config::get('SWAP_EXPIRY_HOURS', '4');
        $expirySec = $expiryHours * 3600;
        $cutoff = time() - $expirySec;

        $stmt = $pdo->prepare("
            SELECT id, ref, from_coin, created_at FROM swap_orders
            WHERE status = 'pending' AND deposit_txid IS NULL AND created_at < ?
        ");
        $stmt->execute([$cutoff]);
        $expired = $stmt->fetchAll();

        if (count($expired) > 0) {
            echo "[" . date('c') . "] DepositWatcher: deleting " . count($expired) . " expired swap(s) (older than {$expiryHours}h, no deposit)\n";
            foreach ($expired as $swap) {
                $ageMin = (int)((time() - $swap['created_at']) / 60);
                echo "  [swap #{$swap['id']} {$swap['ref']}] expired (age: {$ageMin}min, no deposit received)\n";
                $pdo->prepare('DELETE FROM swap_orders WHERE id = ?')->execute([$swap['id']]);
            }
        }

        return $processed;
    }
}

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
        $stmt = $pdo->query('
            SELECT * FROM swap_orders
            WHERE status = "pending" AND deposit_txid IS NULL
            ORDER BY id ASC
        ');
        $pendingSwaps = $stmt->fetchAll();

        foreach ($pendingSwaps as $swap) {
            try {
                $adapter = AdapterRegistry::get($swap['from_coin']);
                // Validate address format locally before hitting API
                $addr = trim($swap['deposit_address']);
                if (!$adapter->hdWallet()->validateAddress($addr)) {
                    throw new \RuntimeException(sprintf(
                        'Locally invalid %s address (len=%d, hex=%s): %s. ' .
                        'This swap was likely created with a different xprv. ' .
                        'Delete it from swap_orders or regenerate the address.',
                        $swap['from_coin'],
                        strlen($addr),
                        bin2hex($addr),
                        $addr
                    ));
                }
                $bal = $adapter->getBalanceSat($addr);
                if ($bal <= 0) continue;

                // Find the incoming tx
                $history = $adapter->api()->getHistory($addr);
                $txIds = $history['tx'] ?? [];
                if (empty($txIds)) continue;
                $txid = $txIds[0]; // most recent first

                // Verify tx and find vout to our address
                $tx = $adapter->api()->getTransaction($txid);
                $txHeight = (int)($tx['height'] ?? 0);
                $confirmations = $adapter->getConfirmations($txHeight);
                $required = $adapter->confirmationsRequired();

                // Save deposit_txid immediately + normalize address in DB (in case of trailing whitespace)
                $pdo->prepare('UPDATE swap_orders SET deposit_txid = ?, deposit_height = ?, confirmations = ?, deposit_address = ? WHERE id = ?')
                    ->execute([$txid, $txHeight, $confirmations, $addr, $swap['id']]);

                if ($confirmations >= $required) {
                    // Mark as confirmed, will be picked up by Dispenser
                    $pdo->prepare('UPDATE swap_orders SET status = "confirmed", confirmed_at = ? WHERE id = ?')
                        ->execute([time(), $swap['id']]);
                }
                $processed++;
            } catch (\Throwable $e) {
                error_log("DepositWatcher swap #{$swap['id']} error: " . $e->getMessage());
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

        return $processed;
    }
}

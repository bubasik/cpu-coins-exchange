#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Manually update a swap order's deposit status.
 *
 * Useful when:
 *   - worker-deposit.php is not running
 *   - You sent funds and want to trigger the swap immediately
 *   - You want to verify the deposit detection logic
 *
 * Usage:
 *   php bin/check-swap.php <ref_or_id>
 *
 * Example:
 *   php bin/check-swap.php SW260628A01352A60
 *   php bin/check-swap.php 42
 *
 * In Docker:
 *   docker compose exec php php bin/check-swap.php SW260628A01352A60
 */

use App\Core\Config;
use App\Core\Database;
use App\Wallet\AdapterRegistry;

require __DIR__ . '/../vendor/autoload.php';
Config::load();

$arg = $argv[1] ?? '';
if (empty($arg)) {
    fwrite(STDERR, "Usage: php bin/check-swap.php <ref_or_id>\n");
    fwrite(STDERR, "Example: php bin/check-swap.php SW260628A01352A60\n");
    exit(1);
}

$pdo = Database::pdo();

// Find by ref or id
if (preg_match('/^SW/', $arg)) {
    $stmt = $pdo->prepare('SELECT * FROM swap_orders WHERE ref = ?');
} else {
    $stmt = $pdo->prepare('SELECT * FROM swap_orders WHERE id = ?');
}
$stmt->execute([$arg]);
$swap = $stmt->fetch();

if (!$swap) {
    fwrite(STDERR, "Swap not found: $arg\n");
    exit(1);
}

echo "=== Swap Order #{$swap['id']} ({$swap['ref']}) ===\n";
echo "  Status:           {$swap['status']}\n";
echo "  From:             {$swap['from_amount_sat']} sats ({$swap['from_coin']})\n";
echo "  To:               {$swap['to_amount_sat']} sats ({$swap['to_coin']})\n";
echo "  Rate:             {$swap['rate']}\n";
echo "  Payout address:   {$swap['payout_address']}\n";
echo "  Deposit address:  {$swap['deposit_address']}\n";
echo "  Deposit txid:     " . ($swap['deposit_txid'] ?? '(none)') . "\n";
echo "  Confirmations:    " . ($swap['confirmations'] ?? 0) . "\n";
echo "  Created at:       " . date('Y-m-d H:i:s', (int)$swap['created_at']) . "\n";
echo "\n";

// Check the deposit via API
$adapter = AdapterRegistry::get($swap['from_coin']);
$addr = trim($swap['deposit_address']);

echo "=== Checking deposit via API ===\n";

// 1. Balance
try {
    $bal = $adapter->getBalanceSat($addr);
    echo "  Balance: $bal sats (" . \App\Wallet\HdWallet::satToCoin($bal) . " {$swap['from_coin']})\n";
} catch (\Throwable $e) {
    echo "  ❌ Balance check failed: " . $e->getMessage() . "\n";
    echo "  → This usually means the address is invalid (wrong xprv).\n";
    exit(1);
}

if ($bal <= 0) {
    echo "\n  ⚠️  No balance received yet. Send {$swap['from_coin']} to: $addr\n";
    exit(0);
}

// 2. History
try {
    $history = $adapter->api()->getHistory($addr);
    $txIds = $history['tx'] ?? [];
    echo "  History: " . count($txIds) . " tx(s)\n";
    if (empty($txIds)) {
        echo "  ⚠️  Balance > 0 but no txs in history. Strange.\n";
        exit(0);
    }
    $txid = $txIds[0];
    echo "  Latest txid: $txid\n";
} catch (\Throwable $e) {
    echo "  ❌ History check failed: " . $e->getMessage() . "\n";
    exit(1);
}

// 3. Tx details
try {
    $tx = $adapter->api()->getTransaction($txid);
    $txHeight = (int)($tx['height'] ?? 0);
    $confirmations = $adapter->getConfirmations($txHeight);
    $required = $adapter->confirmationsRequired();

    echo "  Tx height:       $txHeight\n";
    echo "  Current height:  " . $adapter->api()->getCurrentHeight() . "\n";
    echo "  Confirmations:   $confirmations / $required required\n";

    // Find amount to our address
    $amountSat = 0;
    foreach ($tx['vout'] ?? [] as $vout) {
        $addrs = $vout['scriptPubKey']['addresses'] ?? [];
        if (in_array($addr, $addrs)) {
            $amountSat += (int)$vout['value'];
        }
    }
    echo "  Amount to our address: $amountSat sats (" . \App\Wallet\HdWallet::satToCoin($amountSat) . " {$swap['from_coin']})\n";

    // 4. Update swap_orders
    echo "\n=== Updating swap in DB ===\n";
    $pdo->prepare('UPDATE swap_orders SET deposit_txid = ?, deposit_height = ?, confirmations = ?, deposit_address = ? WHERE id = ?')
        ->execute([$txid, $txHeight, $confirmations, $addr, $swap['id']]);

    if ($confirmations >= $required) {
        $pdo->prepare('UPDATE swap_orders SET status = "confirmed", confirmed_at = ? WHERE id = ?')
            ->execute([time(), $swap['id']]);
        echo "  ✅ Status updated: pending → confirmed\n";
        echo "  → Worker-dispenser will pick this up within 30s and send payout.\n";
    } else {
        echo "  ⏳ Status remains: pending (waiting for $required confirmations, have $confirmations)\n";
        echo "  → Re-run this script in a few minutes.\n";
    }
} catch (\Throwable $e) {
    echo "  ❌ Tx check failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Done ===\n";

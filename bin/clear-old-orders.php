#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Clean up old swap orders and user deposits that were created with previous HD keys.
 *
 * When you regenerate HD wallet keys (or restore from a different xprv), the addresses
 * stored in swap_orders/deposits tables become invalid for the new keys. Run this script
 * to clear them.
 *
 * Usage:
 *   php bin/clear-old-orders.php           # show what would be cleaned
 *   php bin/clear-old-orders.php --force   # actually delete
 *
 * In Docker:
 *   docker exec -it ys-php php bin/clear-old-orders.php
 *   docker exec -it ys-php php bin/clear-old-orders.php --force
 */

use App\Core\Config;
use App\Core\Database;
use App\Wallet\AdapterRegistry;

require __DIR__ . '/../vendor/autoload.php';
Config::load();

$force = in_array('--force', $argv ?? []);
$pdo = Database::pdo();

echo "=== Clear old swap orders and deposits ===\n\n";

// 1. Find swap orders with addresses that don't validate against current HD keys
echo "1. Checking swap_orders for invalid addresses...\n";
$rows = $pdo->query("SELECT id, from_coin, deposit_address, status, created_at FROM swap_orders ORDER BY id")->fetchAll();
$invalid = [];
$valid = [];
foreach ($rows as $r) {
    $addr = trim($r['deposit_address']);
    try {
        $adapter = AdapterRegistry::get($r['from_coin']);
        if ($adapter->hdWallet()->validateAddress($addr)) {
            $valid[] = $r;
        } else {
            $invalid[] = $r;
        }
    } catch (\Throwable $e) {
        echo "   ⚠️  Error checking swap #{$r['id']}: " . $e->getMessage() . "\n";
        $invalid[] = $r;
    }
}

echo "   Valid:   " . count($valid) . " swaps\n";
echo "   Invalid: " . count($invalid) . " swaps (created with old/different xprv)\n\n";

if (empty($invalid)) {
    echo "✓ No invalid swaps found. Nothing to clean.\n";
    exit(0);
}

echo "Invalid swaps:\n";
foreach ($invalid as $r) {
    echo "   #{$r['id']} {$r['from_coin']} status={$r['status']} addr={$r['deposit_address']}\n";
}
echo "\n";

// 2. Same for deposits
echo "2. Checking deposits for invalid addresses...\n";
$rows = $pdo->query("SELECT id, coin, address, status FROM deposits ORDER BY id")->fetchAll();
$invalidDeposits = [];
foreach ($rows as $r) {
    $addr = trim($r['address']);
    try {
        $adapter = AdapterRegistry::get($r['coin']);
        if (!$adapter->hdWallet()->validateAddress($addr)) {
            $invalidDeposits[] = $r;
        }
    } catch (\Throwable $e) {
        $invalidDeposits[] = $r;
    }
}
echo "   Invalid deposits: " . count($invalidDeposits) . "\n\n";

// 3. Delete
if (!$force) {
    echo "Dry run - no changes made. Run with --force to delete:\n";
    echo "  php bin/clear-old-orders.php --force\n";
    exit(0);
}

echo "3. Deleting invalid records...\n";
$del1 = 0;
foreach ($invalid as $r) {
    $stmt = $pdo->prepare("DELETE FROM swap_orders WHERE id = ?");
    $stmt->execute([$r['id']]);
    $del1++;
}
echo "   Deleted $del1 swap orders\n";

$del2 = 0;
foreach ($invalidDeposits as $r) {
    $stmt = $pdo->prepare("DELETE FROM deposits WHERE id = ?");
    $stmt->execute([$r['id']]);
    $del2++;
}
echo "   Deleted $del2 deposits\n\n";

echo "✓ Cleanup complete.\n";

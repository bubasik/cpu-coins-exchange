#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Withdrawal Dispenser Worker
 * Processes confirmed swap payouts and pending user withdrawals.
 *
 * Usage: php bin/worker-dispenser.php
 *
 * WARNING: This process loads xprv (master private keys) from .env.
 * Must run as isolated user, no web access.
 */

use App\Core\Config;
use App\Service\WithdrawalDispenser;

require __DIR__ . '/../vendor/autoload.php';
Config::load();

$intervalSec = 30;
$dispenser = new WithdrawalDispenser();

echo "[" . date('c') . "] WithdrawalDispenser started (interval={$intervalSec}s)\n";

while (true) {
    try {
        $n = $dispenser->tick();
        if ($n > 0) {
            echo "[" . date('c') . "] Processed $n withdrawals\n";
        }
    } catch (\Throwable $e) {
        echo "[" . date('c') . "] ERROR: " . $e->getMessage() . "\n";
        error_log("Dispenser error: " . $e->getMessage());
    }
    sleep($intervalSec);
}

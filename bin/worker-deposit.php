#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Deposit Watcher Worker
 * Polls deposit addresses every 30 seconds, credits balances on confirmation.
 *
 * Usage: php bin/worker-deposit.php
 * Run as a long-running process under supervisor/systemd.
 */

use App\Core\Config;
use App\Service\DepositWatcher;

require __DIR__ . '/../vendor/autoload.php';
Config::load();

$intervalSec = 30;
$watcher = new DepositWatcher();

echo "[" . date('c') . "] DepositWatcher started (interval={$intervalSec}s)\n";

while (true) {
    try {
        $n = $watcher->tick();
        if ($n > 0) {
            echo "[" . date('c') . "] Processed $n deposits\n";
        }
    } catch (\Throwable $e) {
        echo "[" . date('c') . "] ERROR: " . $e->getMessage() . "\n";
        error_log("DepositWatcher error: " . $e->getMessage());
    }
    sleep($intervalSec);
}

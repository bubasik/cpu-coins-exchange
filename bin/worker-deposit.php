#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Deposit Watcher Worker
 * Polls deposit addresses periodically, credits balances on confirmation.
 *
 * Interval is configurable via DEPOSIT_CHECK_INTERVAL env (default 60s).
 * Keep it ≥ 60s to avoid API rate limiting.
 *
 * Usage: php bin/worker-deposit.php
 * Run as a long-running process under supervisor/systemd.
 */

use App\Core\Config;
use App\Service\DepositWatcher;

require __DIR__ . '/../vendor/autoload.php';
Config::load();

// Configurable interval (default 60s to avoid API bans)
$intervalSec = (int)Config::get('DEPOSIT_CHECK_INTERVAL', '60');
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

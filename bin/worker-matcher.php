#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Matching Engine Worker
 * Runs every 1 second, matches open orders against each other.
 *
 * Usage: php bin/worker-matcher.php
 */

use App\Core\Config;
use App\Service\MatchingEngine;

require __DIR__ . '/../vendor/autoload.php';
Config::load();

$intervalMicrosec = 1_000_000; // 1 sec
$engine = new MatchingEngine();

echo "[" . date('c') . "] MatchingEngine started (1s tick)\n";

while (true) {
    $start = microtime(true);
    try {
        $n = $engine->tick();
        if ($n > 0) {
            echo "[" . date('c') . "] Matched $n pairs\n";
        }
    } catch (\Throwable $e) {
        echo "[" . date('c') . "] ERROR: " . $e->getMessage() . "\n";
        error_log("MatchingEngine error: " . $e->getMessage());
    }
    $elapsed = (int)((microtime(true) - $start) * 1_000_000);
    if ($elapsed < $intervalMicrosec) {
        usleep($intervalMicrosec - $elapsed);
    }
}

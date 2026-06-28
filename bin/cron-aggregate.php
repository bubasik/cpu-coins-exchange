#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cron: aggregate 1m candles from trades table.
 * Run every minute: * * * * * php /path/to/bin/cron-aggregate.php
 */

use App\Core\Config;
use App\Service\MatchingEngine;

require __DIR__ . '/../vendor/autoload.php';
Config::load();

$engine = new MatchingEngine();
$engine->aggregateCandles();
echo "[" . date('c') . "] Candles aggregated\n";

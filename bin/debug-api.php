#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Debug API calls from inside Docker.
 *
 * Usage:
 *   docker exec -it ys-php php bin/debug-api.php yenten YYoMoXaUxPGxpbmus23mdGm6guSGVJiocf
 *   docker exec -it ys-php php bin/debug-api.php sugarchain sugar1q...
 *
 * Or without an address (just shows network info):
 *   docker exec -it ys-php php bin/debug-api.php yenten
 *
 * This script tests:
 *   1. DNS resolution
 *   2. HTTPS connectivity
 *   3. Direct curl call to /balance/{address}
 *   4. The App\Api\ApiClient wrapper (uses our actual code)
 *   5. Compares with raw curl output
 */

use App\Core\Config;
use App\Wallet\AdapterRegistry;

require __DIR__ . '/../vendor/autoload.php';
Config::load();

$coin = strtolower($argv[1] ?? '');
$addr = $argv[2] ?? '';

if (!in_array($coin, ['yenten', 'sugarchain', 'adventurecoin', 'advc'])) {
    fwrite(STDERR, "Usage: php bin/debug-api.php <yenten|sugarchain|adventurecoin> [address]\n");
    exit(1);
}

// Normalize
$coin = match ($coin) {
    'advc' => 'adventurecoin',
    default => $coin,
};

// Coin symbol for AdapterRegistry
$coinSymbol = match ($coin) {
    'yenten'        => 'YTN',
    'sugarchain'    => 'SUGAR',
    'adventurecoin' => 'ADVC',
};

echo "=== Debug API for $coin ($coinSymbol) ===\n\n";

// 1. Show config
$apiUrl = match ($coinSymbol) {
    'YTN'   => Config::get('YTN_API', 'https://api.yentencoin.info'),
    'SUGAR' => Config::get('SUGAR_API', 'https://api.sugarchain.org'),
    'ADVC'  => Config::get('ADVC_API', 'https://api2.adventurecoin.quest'),
};
echo "1. Config:\n";
echo "   API URL: '$apiUrl'\n";
echo "   URL bytes: " . bin2hex($apiUrl) . "\n";
echo "   URL length: " . strlen($apiUrl) . "\n";
echo "   Trimmed: '" . trim($apiUrl) . "'\n\n";

// 2. DNS check
$host = parse_url($apiUrl, PHP_URL_HOST);
echo "2. DNS check for $host:\n";
$ip = gethostbyname($host);
if ($ip === $host) {
    echo "   ❌ DNS resolution FAILED\n";
} else {
    echo "   ✅ Resolved to $ip\n";
}
echo "\n";

// 3. Network reachability
echo "3. Network test (curl HEAD):\n";
$ch = curl_init($apiUrl . '/info');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_NOBODY => false,
    CURLOPT_HEADER => true,
]);
$body = curl_exec($ch);
$err = curl_error($ch);
$info = curl_getinfo($ch);
curl_close($ch);
if ($err) {
    echo "   ❌ curl error: $err\n";
} else {
    echo "   ✅ HTTP code: " . $info['http_code'] . "\n";
    echo "   Total time: " . $info['total_time'] . "s\n";
    echo "   SSL verify: " . ($info['ssl_verify_result'] === 0 ? 'OK' : 'FAILED (' . $info['ssl_verify_result'] . ')') . "\n";
}
echo "\n";

// 4. If no address given, list pending swaps from DB
if (empty($addr)) {
    echo "4. Pending swap orders in DB:\n";
    $pdo = \App\Core\Database::pdo();
    $rows = $pdo->query("SELECT id, from_coin, deposit_address, status FROM swap_orders WHERE status = 'pending' LIMIT 20")->fetchAll();
    if (empty($rows)) {
        echo "   (no pending swaps)\n";
    } else {
        foreach ($rows as $r) {
            $a = $r['deposit_address'];
            echo "   #{$r['id']} {$r['from_coin']} addr='{$a}' (len=" . strlen($a) . ", bytes=" . bin2hex($a) . ")\n";
        }
    }
    echo "\n";

    if (empty($rows)) {
        echo "No addresses to test. Pass an address as 2nd arg:\n";
        echo "  php bin/debug-api.php yenten YYo...\n";
        exit(0);
    }

    // Use first pending swap's address for the test
    $addr = $rows[0]['deposit_address'];
    $coin = strtolower($rows[0]['from_coin']);
    echo "   Using first pending swap address: $addr\n\n";
}

// 5. Test address: raw curl
echo "5. Raw curl test for /balance/$addr:\n";
$cleanAddr = trim($addr);
// $coinSymbol was set earlier (line ~44)
$url = rtrim(trim($apiUrl), '/') . '/balance/' . $cleanAddr;
echo "   URL: $url\n";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_USERAGENT => 'YentenSugarExchange/1.0',
]);
$body = curl_exec($ch);
$err = curl_error($ch);
$info = curl_getinfo($ch);
curl_close($ch);
if ($err) {
    echo "   ❌ curl error: $err\n";
} else {
    echo "   HTTP code: " . $info['http_code'] . "\n";
    echo "   Body: $body\n";
}
echo "\n";

// 6. Test address: via our ApiClient
echo "6. ApiClient wrapper test:\n";
try {
    $adapter = AdapterRegistry::get($coinSymbol);
    $bal = $adapter->getBalanceSat($cleanAddr);
    echo "   ✅ Balance: $bal sats\n";
} catch (\Throwable $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// 7. Test address: via /history (different endpoint)
echo "7. /history endpoint test:\n";
$url = rtrim(trim($apiUrl), '/') . '/history/' . $cleanAddr;
echo "   URL: $url\n";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
]);
$body = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);
if ($err) {
    echo "   ❌ curl error: $err\n";
} else {
    echo "   Body: " . substr($body, 0, 200) . (strlen($body) > 200 ? '...' : '') . "\n";
}
echo "\n";

echo "=== Debug complete ===\n";

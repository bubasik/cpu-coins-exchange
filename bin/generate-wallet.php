#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Generate HD wallet keys for a coin.
 * Usage: php bin/generate-wallet.php yenten
 *        php bin/generate-wallet.php sugarchain
 *
 * Prints xprv, xpub, and first deposit address.
 * Store xprv SECURELY (it controls all derived addresses).
 */

use App\Core\Config;
use App\Network\YentenNetwork;
use App\Network\SugarchainNetwork;
use App\Wallet\HdWallet;

require __DIR__ . '/../vendor/autoload.php';
Config::load();

$coin = strtolower($argv[1] ?? '');
if (!in_array($coin, ['yenten', 'sugarchain', 'adventurecoin', 'advc'])) {
    fwrite(STDERR, "Usage: php bin/generate-wallet.php <yenten|sugarchain|adventurecoin>\n");
    exit(1);
}

// Normalize coin name
$coin = match ($coin) {
    'advc' => 'adventurecoin',
    default => $coin,
};

$network = match ($coin) {
    'yenten'        => new YentenNetwork(),
    'sugarchain'    => new SugarchainNetwork(),
    'adventurecoin' => new \App\Network\AdventurecoinNetwork(),
};
$wallet = new HdWallet($network);
$keys = $wallet->generateMasterKey();

echo "Coin:        " . strtoupper($coin) . "\n";
echo "Network:     " . $network->getNetCode() . "\n";
try {
    echo "Bech32 HRP:  " . $network->getSegwitBech32Prefix() . "\n";
} catch (\Throwable $e) {
    echo "Bech32 HRP:  (none)\n";
}
echo "\n";
echo "xprv (KEEP SECRET, store in .env as {$coin}_XPRV):\n";
echo "  " . $keys['xprv'] . "\n";
echo "\n";
echo "xpub (safe to share, store in .env as {$coin}_XPUB):\n";
echo "  " . $keys['xpub'] . "\n";
echo "\n";
echo "First deposit address (m/0/0):\n";
echo "  " . $keys['first_address'] . "\n";
echo "\n";
echo "First char should be: " . ($coin === 'yenten' ? 'Y' : 'S') . "\n";
echo "\n";
echo "Use this address as {$coin}_HOT_ADDRESS after funding it.\n";

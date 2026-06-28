#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Diagnose HD wallet setup for a coin.
 * Verifies that:
 *   - xprv/xpub in .env are valid
 *   - hot_wallet_address matches m/0/0 of xpub
 *   - UTXOs on hot_wallet_address can be spent (deriv_index found)
 *
 * Usage: php bin/diag-wallet.php <yenten|sugarchain|adventurecoin>
 *   docker compose exec php php bin/diag-wallet.php adventurecoin
 */

use App\Core\Config;
use App\Network\YentenNetwork;
use App\Network\SugarchainNetwork;
use App\Network\AdventurecoinNetwork;
use App\Wallet\HdWallet;
use BitWasp\Bitcoin\Address\AddressCreator;

require __DIR__ . '/../vendor/autoload.php';
Config::load();

$coin = strtolower($argv[1] ?? 'yenten');
$coin = match ($coin) {
    'advc', 'adventure' => 'adventurecoin',
    default => $coin,
};

if (!in_array($coin, ['yenten', 'sugarchain', 'adventurecoin'])) {
    fwrite(STDERR, "Usage: php bin/diag-wallet.php <yenten|sugarchain|adventurecoin>\n");
    exit(1);
}

$network = match ($coin) {
    'yenten'        => new YentenNetwork(),
    'sugarchain'    => new SugarchainNetwork(),
    'adventurecoin' => new AdventurecoinNetwork(),
};

$sym = match ($coin) {
    'yenten'        => 'YTN',
    'sugarchain'    => 'SUGAR',
    'adventurecoin' => 'ADVC',
};

echo "=== Diagnostic for $coin ($sym) ===\n\n";

$xpub = Config::get("{$sym}_XPUB", '');
$xprv = Config::get("{$sym}_XPRV", '');
$hotAddr = Config::get("{$sym}_HOT_ADDRESS", '');

echo "Config:\n";
echo "  XPUB: " . substr($xpub, 0, 50) . "...\n";
echo "  XPRV: " . (empty($xprv) ? '(empty)' : substr($xprv, 0, 50) . "...") . "\n";
echo "  HOT_ADDRESS: $hotAddr\n\n";

if (empty($xpub) || strpos($xpub, 'your-') !== false) {
    echo "❌ XPUB not configured. Run: php bin/generate-wallet.php $coin\n";
    exit(1);
}

$wallet = new HdWallet($network);
$creator = new AddressCreator();

// Derive first 5 addresses from xpub
echo "First 5 addresses from XPUB (m/0/N):\n";
for ($i = 0; $i < 5; $i++) {
    try {
        $addr = $wallet->deriveAddressFromXpub($xpub, $i);
        $script = $creator->fromString($addr, $network)->getScriptPubKey()->getHex();
        $match = ($addr === $hotAddr) ? ' ← MATCHES HOT_ADDRESS' : '';
        echo "  [$i] $addr (script: $script)$match\n";
    } catch (\Throwable $e) {
        echo "  [$i] ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n";

// Check if hot_wallet_address is in the HD tree
echo "Checking if HOT_ADDRESS is in HD tree:\n";
$found = false;
for ($i = 0; $i < 1000; $i++) {
    try {
        $addr = $wallet->deriveAddressFromXpub($xpub, $i);
        if ($addr === $hotAddr) {
            echo "  ✅ HOT_ADDRESS found at index $i\n";
            $found = true;
            break;
        }
    } catch (\Throwable $e) {
        continue;
    }
}
if (!$found) {
    echo "  ❌ HOT_ADDRESS NOT found in first 1000 derived addresses!\n";
    echo "     This means HOT_ADDRESS was generated with a DIFFERENT xprv.\n";
    echo "     Fix: either update HOT_ADDRESS to m/0/0 of current xpub, or regenerate keys.\n";
}

echo "\n";

// Check UTXOs on hot address
echo "UTXOs on HOT_ADDRESS:\n";
try {
    $adapterClass = match ($coin) {
        'yenten'        => \App\Api\YentenApi::class,
        'sugarchain'    => \App\Api\SugarchainApi::class,
        'adventurecoin' => \App\Api\AdventurecoinApi::class,
    };
    $utxos = $adapterClass::client()->getUnspent($hotAddr);
    if (empty($utxos)) {
        echo "  ⚠️  No UTXOs. Send some $sym to $hotAddr to fund the hot wallet.\n";
    } else {
        foreach ($utxos as $u) {
            echo "  txid={$u['txid']} vout={$u['index']} value={$u['value']} script={$u['script']}\n";
            // Find deriv index for this script
            $foundIdx = null;
            for ($i = 0; $i < 1000; $i++) {
                try {
                    $addr = $wallet->deriveAddressFromXpub($xpub, $i);
                    $myScript = $creator->fromString($addr, $network)->getScriptPubKey()->getHex();
                    if (strtolower($myScript) === strtolower($u['script'])) {
                        $foundIdx = $i;
                        break;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
            if ($foundIdx !== null) {
                echo "    ✅ deriv_index=$foundIdx (private key can be derived)\n";
            } else {
                echo "    ❌ deriv_index NOT FOUND in 0..999\n";
                echo "       The UTXO exists but we can't spend it (different xprv).\n";
            }
        }
    }
} catch (\Throwable $e) {
    echo "  ❌ Error fetching UTXOs: " . $e->getMessage() . "\n";
}

echo "\n=== Diagnostic complete ===\n";

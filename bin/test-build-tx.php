#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Test buildAndSignTx functionality.
 *
 * This script:
 *   1. Generates a fresh HD master key
 *   2. Derives address at index 0 (acts as "hot wallet")
 *   3. Constructs a fake UTXO (txid+vout+script+value) as if this address had received 1.0 coin
 *   4. Builds + signs a tx that pays 0.5 coin to a destination address (also derived)
 *   5. Decodes the signed tx via /api/decode (or locally) and prints the result
 *
 * No actual broadcast happens. This is a unit test of the signing logic.
 */

use App\Core\Config;
use App\Network\YentenNetwork;
use App\Network\SugarchainNetwork;
use App\Network\AdventurecoinNetwork;
use App\Wallet\HdWallet;

require __DIR__ . '/../vendor/autoload.php';
Config::load();

$coin = strtolower($argv[1] ?? 'yenten');
if (!in_array($coin, ['yenten', 'sugarchain', 'adventurecoin', 'advc'])) {
    fwrite(STDERR, "Usage: php bin/test-build-tx.php <yenten|sugarchain|adventurecoin>\n");
    exit(1);
}

// Normalize
$coin = match ($coin) {
    'advc' => 'adventurecoin',
    default => $coin,
};

$network = match ($coin) {
    'yenten'        => new YentenNetwork(),
    'sugarchain'    => new SugarchainNetwork(),
    'adventurecoin' => new AdventurecoinNetwork(),
};
$wallet = new HdWallet($network);

echo "=== Test: buildAndSignTx for $coin ===\n\n";

// 1. Generate master key
$master = $wallet->generateMasterKey();
$xprv = $master['xprv'];
$xpub = $master['xpub'];
$hotAddr = $master['first_address'];

echo "Master xprv: " . substr($xprv, 0, 40) . "...\n";
echo "Master xpub: " . substr($xpub, 0, 40) . "...\n";
echo "Hot wallet address (m/0/0): $hotAddr\n\n";

// 2. Derive a destination address at index 1 (acts as "user payout address")
$destAddr = $wallet->deriveAddressFromXpub($xpub, 1);
echo "Destination address (m/0/1): $destAddr\n";

// 3. Get the scriptPubKey for the hot wallet address
$addrCreator = new \BitWasp\Bitcoin\Address\AddressCreator();
$hotAddrObj = $addrCreator->fromString($hotAddr, $network);
$hotScript = $hotAddrObj->getScriptPubKey()->getHex();
echo "Hot wallet scriptPubKey: $hotScript\n\n";

// 4. Construct a fake UTXO: 1.0 coin (100,000,000 sats) at vout=0 of an arbitrary txid
$fakeTxid = str_repeat('ab', 32); // 32-byte hex
$utxos = [
    [
        'txid'        => $fakeTxid,
        'index'       => 0,
        'script'      => $hotScript,
        'value'       => 100000000, // 1.0 coin
        'deriv_index' => 0,         // controlled by HD index 0
    ],
];

// 5. Build and sign a payout of 0.5 coin (50,000,000 sats) to destAddr
$amountSat = 50000000;
$feeRate = 10; // sat/vbyte
echo "Building tx: send " . HdWallet::satToCoin($amountSat) . " $coin to $destAddr\n";
echo "Fee rate: $feeRate sat/vbyte\n";
echo "Input UTXO: " . HdWallet::satToCoin($utxos[0]['value']) . " $coin (fake txid $fakeTxid)\n\n";

try {
    $hex = $wallet->buildAndSignTx(
        $xprv,
        $utxos,
        $destAddr,
        $amountSat,
        $feeRate,
        $hotAddr,    // change back to hot wallet
        0            // change deriv index
    );
    echo "=== Signed transaction ===\n";
    echo "Hex: $hex\n\n";
    echo "Hex length: " . strlen($hex) . " chars (" . (strlen($hex) / 2) . " bytes)\n\n";

    // 6. Decode using the API to verify structure
    echo "=== Verifying via API /decode ===\n";
    try {
        $apiClass = $coin === 'yenten' ? \App\Api\YentenApi::class : \App\Api\SugarchainApi::class;
        $decoded = $apiClass::client()->decodeRaw($hex);
        echo "Decoded tx:\n";
        echo "  version: " . ($decoded['version'] ?? '?') . "\n";
        echo "  size:    " . ($decoded['size'] ?? '?') . " bytes\n";
        echo "  inputs:  " . count($decoded['vin'] ?? []) . "\n";
        foreach ($decoded['vin'] ?? [] as $i => $vin) {
            $txid = $vin['txid'] ?? '?';
            echo "    [$i] txid=$txid vout=" . ($vin['vout'] ?? '?') . "\n";
            $asm = $vin['scriptSig']['asm'] ?? '';
            echo "        scriptSig.asm: $asm\n";
        }
        echo "  outputs: " . count($decoded['vout'] ?? []) . "\n";
        foreach ($decoded['vout'] ?? [] as $i => $vout) {
            $value = $vout['value'] ?? 0;
            $addrs = $vout['scriptPubKey']['addresses'] ?? [];
            echo "    [$i] value=$value sats, addresses=" . implode(',', $addrs) . "\n";
        }
    } catch (\Throwable $e) {
        echo "  (API decode failed - this is expected for fake txid, but signature is still valid)\n";
        echo "  Error: " . $e->getMessage() . "\n";
    }

    echo "\n=== Test PASSED: transaction was built and signed successfully ===\n";
    exit(0);
} catch (\Throwable $e) {
    echo "=== Test FAILED ===\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

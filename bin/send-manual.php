#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Manually send coins from hot wallet to a destination address.
 *
 * Tries both byte order variants for txid (flipped and not flipped)
 * because different API servers return txid in different endianness.
 *
 * Usage:
 *   php bin/send-manual.php <coin> <to_address> <amount>
 *
 * Example (send 1 ADVC):
 *   docker compose exec php php bin/send-manual.php adventurecoin AKKU97uYspzyAZQGe5Ec9BBMvSDnGAVB4z 1
 *
 * Example (send 4.975 ADVC — exact swap payout):
 *   docker compose exec php php bin/send-manual.php adventurecoin AKKU97uYspzyAZQGe5Ec9BBMvSDnGAVB4z 4.975
 */

use App\Core\Config;
use App\Network\YentenNetwork;
use App\Network\SugarchainNetwork;
use App\Network\AdventurecoinNetwork;
use App\Wallet\HdWallet;
use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Bitcoin\Transaction\Factory\Signer;
use BitWasp\Bitcoin\Transaction\SignatureHash\SigHash;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\Interpreter\Checker;
use BitWasp\Bitcoin\Script\Interpreter\Interpreter;
use BitWasp\Buffertools\Buffer;

require __DIR__ . '/../vendor/autoload.php';
Config::load();

$coin = strtolower($argv[1] ?? '');
$toAddress = $argv[2] ?? '';
$amount = $argv[3] ?? '';

if (!in_array($coin, ['yenten', 'sugarchain', 'adventurecoin', 'advc']) || empty($toAddress) || empty($amount)) {
    fwrite(STDERR, "Usage: php bin/send-manual.php <coin> <to_address> <amount>\n");
    fwrite(STDERR, "Example: php bin/send-manual.php adventurecoin AKKU97uYspzyAZQGe5Ec9BBMvSDnGAVB4z 1\n");
    exit(1);
}

$coin = match ($coin) {
    'advc' => 'adventurecoin',
    default => $coin,
};

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

echo "=== Manual send: $amount $sym → $toAddress ===\n\n";

$xprv = Config::get("{$sym}_XPRV", '');
$xpub = Config::get("{$sym}_XPUB", '');
$hotAddr = Config::get("{$sym}_HOT_ADDRESS", '');

if (empty($xprv) || strpos($xprv, 'your-') !== false) {
    fwrite(STDERR, "❌ {$sym}_XPRV not configured in .env\n");
    exit(1);
}

$wallet = new HdWallet($network);
$adapterClass = match ($coin) {
    'yenten'        => \App\Api\YentenApi::class,
    'sugarchain'    => \App\Api\SugarchainApi::class,
    'adventurecoin' => \App\Api\AdventurecoinApi::class,
};

// 1. Get UTXOs
echo "1. Fetching UTXOs for $hotAddr\n";
$utxos = $adapterClass::client()->getUnspent($hotAddr);
if (empty($utxos)) {
    fwrite(STDERR, "❌ No UTXOs on hot wallet. Send some $sym to $hotAddr first.\n");
    exit(1);
}
echo "   Found " . count($utxos) . " UTXO(s)\n";

// 2. Find deriv_index for the first UTXO
echo "\n2. Finding deriv_index for first UTXO\n";
$creator = new AddressCreator();
$derivIndex = 0;
foreach ($utxos as $u) {
    $scriptHex = $u['script'];
    $found = false;
    for ($i = 0; $i < 1000; $i++) {
        try {
            $addr = $wallet->deriveAddressFromXpub($xpub, $i);
            $myScript = $creator->fromString($addr, $network)->getScriptPubKey()->getHex();
            if (strtolower($myScript) === strtolower($scriptHex)) {
                $derivIndex = $i;
                $found = true;
                echo "   UTXO txid={$u['txid']} vout={$u['index']} → deriv_index=$i\n";
                break;
            }
        } catch (\Throwable $e) { continue; }
    }
    if (!$found) {
        fwrite(STDERR, "❌ deriv_index not found for UTXO {$u['txid']}\n");
        exit(1);
    }
    break; // use first UTXO only
}

// 3. Parse amount
$amountSat = (int)bcmul($amount, '100000000', 0);
echo "\n3. Amount: $amountSat sats ($amount $sym)\n";

// 4. Get fee rate (capped to avoid "absurdly-high-fee" rejection)
try {
    $fee = $adapterClass::client()->getFee();
    $rawRate = (int)($fee['feerate'] ?? 10);
    // Cap at 100 sat/vB — some APIs return absurd values (1M+)
    $feeRate = max(1, min($rawRate, 100));
    echo "   Fee rate: $feeRate sat/vB (API returned $rawRate, capped at 100)\n";
} catch (\Throwable $e) {
    $feeRate = 10;
    echo "   Fee rate: $feeRate sat/vB (default, API fetch failed)\n";
}

// 5. Build and sign tx — try BOTH byte orders
$hkf = new HierarchicalKeyFactory();
$master = $hkf->fromExtended($xprv, $network);
$privKey = $master->derivePath("0/$derivIndex")->getPrivateKey();

$destAddr = $creator->fromString($toAddress, $network);
$changeAddr = $creator->fromString($hotAddr, $network);

$utxo = $utxos[0];
$totalIn = (int)$utxo['value'];
$estimatedSize = 10 + 148 + 2 * 34;  // 1 input, 2 outputs
$requiredFee = (int)ceil($estimatedSize * $feeRate);
$sendAmount = $amountSat;
$changeAmount = $totalIn - $sendAmount - $requiredFee;

echo "\n4. Tx params:\n";
echo "   Input:  txid={$utxo['txid']} vout={$utxo['index']} value=$totalIn\n";
echo "   Output: $sendAmount sats → $toAddress\n";
echo "   Change: $changeAmount sats → $hotAddr\n";
echo "   Fee:    $requiredFee sats ($feeRate sat/vB × ~$estimatedSize vB)\n\n";

if ($changeAmount < 546) {
    fwrite(STDERR, "❌ Change amount ($changeAmount) below dust threshold (546). Need more input.\n");
    exit(1);
}

// Try WITHOUT flip first (correct for sugarchain-project/api-server)
// Fall back to WITH flip if that fails
foreach ([false, true] as $useFlip) {
    $label = $useFlip ? "WITH flip (fallback)" : "WITHOUT flip (standard)";
    echo "5. Building tx $label\n";

    try {
        $txBuilder = TransactionFactory::build();
        $txBuilder->version(1);

        $hashBuf = $useFlip
            ? Buffer::hex($utxo['txid'])->flip()
            : Buffer::hex($utxo['txid']);

        $outPoint = new OutPoint($hashBuf, (int)$utxo['index']);
        $txBuilder->spendOutPoint($outPoint);

        $scriptBuf = Buffer::hex($utxo['script']);
        $txOut = new TransactionOutput($totalIn, ScriptFactory::fromBuffer($scriptBuf));

        $txBuilder->output($sendAmount, $destAddr->getScriptPubKey());
        $txBuilder->output($changeAmount, $changeAddr->getScriptPubKey());

        $unsigned = $txBuilder->get();

        // Sign
        $signData = new \BitWasp\Bitcoin\Transaction\Factory\SignData();
        $checker = new Checker(
            \BitWasp\Bitcoin\Bitcoin::getEcAdapter(),
            $unsigned, 0, $totalIn
        );
        $inputSigner = new \BitWasp\Bitcoin\Transaction\Factory\InputSigner(
            \BitWasp\Bitcoin\Bitcoin::getEcAdapter(),
            $unsigned, 0, $txOut, $signData, $checker
        );
        $inputSigner->extract();
        $inputSigner->sign($privKey, SigHash::ALL);
        $sig = $inputSigner->serializeSignatures();

        $newBuilder = TransactionFactory::build();
        $newBuilder->version($unsigned->getVersion());
        foreach ($unsigned->getInputs() as $idx => $in) {
            $scriptSig = $sig->getScriptSig();
            $newBuilder->spendOutPoint($in->getOutPoint(), $scriptSig, $in->getSequence());
        }
        foreach ($unsigned->getOutputs() as $out) {
            $newBuilder->output($out->getValue(), $out->getScript());
        }
        $newBuilder->locktime($unsigned->getLockTime());
        $signed = $newBuilder->get();
        $hex = $signed->getHex();

        echo "   Signed tx: " . strlen($hex) . " hex chars (" . (strlen($hex)/2) . " bytes)\n";

        // Decode via API to verify structure
        echo "   Decoding via API...\n";
        $decoded = $adapterClass::client()->decodeRaw($hex);
        $decodedTxid = $decoded['vin'][0]['txid'] ?? '?';
        echo "   Decoded input txid: $decodedTxid\n";
        echo "   Original txid:      {$utxo['txid']}\n";
        if ($decodedTxid === $utxo['txid']) {
            echo "   ✅ txid matches (API shows same as we put in)\n";
        } else {
            echo "   ⚠️  txid MISMATCH — byte order issue\n";
        }

        // Broadcast
        echo "   Broadcasting...\n";
        $txid = $adapterClass::client()->broadcast($hex);
        echo "   ✅ SUCCESS! Broadcast txid: $txid\n";
        echo "\n   Sent $amount $sym to $toAddress\n";
        echo "   Tx: $txid\n";
        exit(0);
    } catch (\Throwable $e) {
        echo "   ❌ Failed: " . $e->getMessage() . "\n\n";
        continue;
    }
}

echo "\n❌ Both byte order variants failed. Check error messages above.\n";
exit(1);

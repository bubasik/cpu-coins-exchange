<?php
declare(strict_types=1);

namespace App\Wallet;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Bitcoin\Transaction\Factory\Signer;
use BitWasp\Bitcoin\Transaction\SignatureHash\SigHash;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Buffertools\Buffer;

/**
 * HD wallet helper - derives addresses from xpub/xprv, builds and signs transactions.
 *
 * xprv (master private key) is ONLY loaded in worker processes, never in the web process.
 * The web process uses xpub-only derivation for deposit address generation.
 */
final class HdWallet
{
    private NetworkInterface $network;

    public function __construct(NetworkInterface $network)
    {
        $this->network = $network;
        // NOTE: Do NOT call Bitcoin::setNetwork() here.
        // It's a global static state that gets overwritten when multiple
        // HdWallet instances exist (e.g. YentenAdapter + SugarchainAdapter),
        // causing addresses to be generated with the wrong network params.
        // Always pass $this->network explicitly to bitcoin-php functions.
    }

    /** Derive a deposit address (P2PKH legacy) from xpub at given index. */
    public function deriveAddressFromXpub(string $xpub, int $index): string
    {
        $hkf = new HierarchicalKeyFactory();
        $key = $hkf->fromExtended($xpub, $this->network);
        $child = $key->derivePath("0/" . $index);
        // Pass network explicitly to getAddress to avoid global state issues
        return $child->getAddress(new AddressCreator())->getAddress($this->network);
    }

    /** Validate address format for the network (throws on invalid). */
    public function validateAddress(string $address): bool
    {
        try {
            $creator = new AddressCreator();
            $creator->fromString($address, $this->network);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Build and sign a transaction using xprv (worker process only).
     *
     * Strategy: P2PKH inputs only (legacy addresses).
     *   - Inputs come from our HD wallet at known derivation indices.
     *   - Each UTXO MUST include a `deriv_index` field (the HD child index used to
     *     generate the address). The caller (adapter) is responsible for tracking this.
     *   - Output #0: payment to recipient (any address type: P2PKH/P2SH/Bech32)
     *   - Output #1: change back to hot wallet (P2PKH)
     *
     * @param string $xprv                  Master private key (extended)
     * @param array  $utxos                 [{txid, index (vout), script (hex), value (sat), deriv_index (int)}, ...]
     * @param string $toAddress             Recipient address (any type)
     * @param int    $sendAmountSat         Amount to send in satoshis
     * @param int    $feeRateSatPerB        Fee rate in sat/vbyte
     * @param string $changeAddress         Change address (same network)
     * @param int    $changeDerivIndex      HD index of change key (for re-signing if needed)
     * @return string                       Signed raw tx hex
     */
    public function buildAndSignTx(
        string $xprv,
        array $utxos,
        string $toAddress,
        int $sendAmountSat,
        int $feeRateSatPerB,
        string $changeAddress,
        int $changeDerivIndex = 0
    ): string {
        if (empty($utxos)) {
            throw new \RuntimeException('No UTXOs to spend');
        }

        $hkf = new HierarchicalKeyFactory();
        $master = $hkf->fromExtended($xprv, $this->network);
        $addrCreator = new AddressCreator();

        // Parse destination and change addresses
        $destAddr = $addrCreator->fromString($toAddress, $this->network);
        $changeAddr = $addrCreator->fromString($changeAddress, $this->network);

        // Select UTXOs greedily until we cover sendAmount + estimated fee
        $selected = [];
        $totalIn = 0;

        // Estimate fee: 10 (overhead) + N*148 (each P2PKH input) + 2*34 (2 outputs) bytes
        // Then multiply by feeRate
        $estimatedSize = 10 + count($utxos) * 148 + 2 * 34;
        $requiredFee = (int)ceil($estimatedSize * $feeRateSatPerB);
        $target = $sendAmountSat + $requiredFee;

        foreach ($utxos as $u) {
            $selected[] = $u;
            $totalIn += (int)$u['value'];
            if ($totalIn >= $target) break;
        }
        if ($totalIn < $target) {
            throw new \RuntimeException(sprintf(
                'Insufficient funds: have %d sat, need %d (send=%d + fee=%d)',
                $totalIn, $target, $sendAmountSat, $requiredFee
            ));
        }
        $changeAmount = $totalIn - $sendAmountSat - $requiredFee;

        // Build unsigned transaction
        $txBuilder = TransactionFactory::build();
        $txBuilder->version(1);

        $inputTxOuts = []; // Track corresponding prev outputs for signing
        foreach ($selected as $u) {
            $txid = $u['txid'];
            // txid from explorer is big-endian (display format).
            // OutPoint expects little-endian internal hash. Use Buffer::flip().
            $hashBuf = Buffer::hex($txid)->flip();
            $outPoint = new OutPoint($hashBuf, (int)$u['index']);
            // spendOutPoint: pass null scriptSig (will be set during signing)
            $txBuilder->spendOutPoint($outPoint);

            // Build a TransactionOutput that represents the UTXO (needed for Signer)
            // The script here is the scriptPubKey of the previous output (P2PKH)
            $scriptBuf = Buffer::hex($u['script']);
            $inputTxOuts[] = new TransactionOutput((int)$u['value'], ScriptFactory::fromBuffer($scriptBuf));
        }

        // Add outputs
        $txBuilder->output($sendAmountSat, $destAddr->getScriptPubKey());
        // Dust threshold: 546 sats
        if ($changeAmount > 546) {
            $txBuilder->output($changeAmount, $changeAddr->getScriptPubKey());
        }

        $unsigned = $txBuilder->get();

        // Sign each input using InputSigner (avoids Signer::get() which uses
        // SplFixedArray::rewind() — removed in PHP 8.4)
        $signedTx = $unsigned;
        foreach ($selected as $i => $u) {
            $derivIdx = (int)($u['deriv_index'] ?? 0);
            $privKey = $master->derivePath("0/" . $derivIdx)->getPrivateKey();

            // Build InputSigner for this input
            $signData = new \BitWasp\Bitcoin\Transaction\Factory\SignData();
            $checker = new \BitWasp\Bitcoin\Script\Interpreter\Checker(
                Bitcoin::getEcAdapter(),
                $signedTx,
                $i,
                (int)$selected[$i]['value']
            );
            $inputSigner = new \BitWasp\Bitcoin\Transaction\Factory\InputSigner(
                Bitcoin::getEcAdapter(),
                $signedTx,
                $i,
                $inputTxOuts[$i],
                $signData,
                $checker
            );
            $inputSigner->extract();
            $inputSigner->sign($privKey, SigHash::ALL);
            $sig = $inputSigner->serializeSignatures();

            // Manually apply the scriptSig to the transaction input.
            // Avoid inputsMutator (uses SplFixedArray::rewind, removed in PHP 8.4)
            // Instead, build a fresh transaction with the scriptSig applied.
            $newBuilder = TransactionFactory::build();
            $newBuilder->version($signedTx->getVersion());
            foreach ($signedTx->getInputs() as $idx => $in) {
                $scriptSig = ($idx === $i) ? $sig->getScriptSig() : ($in->getScriptSig() ?: null);
                $newBuilder->spendOutPoint($in->getOutPoint(), $scriptSig, $in->getSequence());
            }
            foreach ($signedTx->getOutputs() as $out) {
                $newBuilder->output($out->getValue(), $out->getScript());
            }
            $newBuilder->locktime($signedTx->getLockTime());
            $signedTx = $newBuilder->get();
        }

        return $signedTx->getHex();
    }

    /** Convert satoshis to display string (e.g. "1.23456789"). */
    public static function satToCoin(int $sat, int $decimals = 8): string
    {
        $neg = $sat < 0;
        $sat = (string)abs($sat);
        while (strlen($sat) <= $decimals) $sat = '0' . $sat;
        $intPart = substr($sat, 0, strlen($sat) - $decimals);
        $fracPart = substr($sat, strlen($sat) - $decimals);
        $fracPart = rtrim($fracPart, '0');
        $out = $intPart . ($fracPart !== '' ? '.' . $fracPart : '');
        return $neg ? '-' . $out : $out;
    }

    /** Convert display string ("1.5") to satoshis (150000000). */
    public static function coinToSat(string $amount, int $decimals = 8): int
    {
        if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
            throw new \InvalidArgumentException("Invalid amount: $amount");
        }
        [$int, $frac] = array_pad(explode('.', $amount, 2), 2, '');
        $frac = str_pad(substr($frac, 0, $decimals), $decimals, '0');
        return (int)($int . $frac);
    }

    /**
     * Generate a new master HD key (for testing/setup).
     * Use bin/generate-wallet.php for production.
     */
    public function generateMasterKey(): array
    {
        $hkf = new HierarchicalKeyFactory();
        $master = $hkf->generateMasterKey(new Random());
        return [
            'xprv' => $master->toExtendedKey($this->network),
            'xpub' => $master->toExtendedPublicKey($this->network),
            'first_address' => $master->derivePath("0/0")->getAddress(new AddressCreator())->getAddress($this->network),
        ];
    }
}

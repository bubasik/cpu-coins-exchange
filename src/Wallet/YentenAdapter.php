<?php
declare(strict_types=1);

namespace App\Wallet;

use App\Api\YentenApi;
use App\Core\Config;

final class YentenAdapter implements CoinAdapter
{
    private HdWallet $wallet;

    public function __construct()
    {
        $this->wallet = new HdWallet(YentenApi::network());
    }

    public function symbol(): string { return 'YTN'; }
    public function name(): string { return 'Yenten'; }
    public function decimals(): int { return 8; }
    public function api(): \App\Api\ApiClient { return YentenApi::client(); }
    public function hdWallet(): HdWallet { return $this->wallet; }
    public function confirmationsRequired(): int { return YentenApi::confirmationsRequired(); }
    public function xpub(): string { return Config::get('YTN_XPUB', ''); }
    public function xprv(): string { return Config::get('YTN_XPRV', ''); }
    public function hotWalletAddress(): string { return Config::get('YTN_HOT_ADDRESS', ''); }
    public function hdBasePath(): string { return Config::get('YTN_HD_PATH', "m/44'/1234'/0'/0/"); }

    public function deriveDepositAddress(int $index): string
    {
        $xpub = $this->xpub();
        if (empty($xpub) || strpos($xpub, 'your-') !== false) {
            throw new \RuntimeException('YTN_XPUB not configured. Run: php bin/generate-wallet.php yenten');
        }
        return $this->wallet->deriveAddressFromXpub($xpub, $index);
    }

    public function buildPayoutTx(
        string $toAddress,
        int $amountSat,
        int $feeRateSatPerB,
        ?int $changeIndex = null
    ): string {
        $xprv = $this->xprv();
        if (empty($xprv) || strpos($xprv, 'your-') !== false) {
            throw new \RuntimeException('YTN_XPRV not configured');
        }

        // Fetch UTXOs from the hot wallet address
        $utxos = $this->api()->getUnspent($this->hotWalletAddress());
        if (empty($utxos)) {
            throw new \RuntimeException('No UTXOs in hot wallet. Sweep funds into the hot wallet address first.');
        }

        // For each UTXO we need its derivation index (the HD child that controls it).
        // For simplicity, we look up addresses that we previously generated for deposits and find
        // which one matches the UTXO's script. This is tracked by the DepositWatcher.
        // For now, assume all UTXOs were sent to address at index 0 (the hot wallet's first key).
        // Production: store derivation index alongside deposit_address in DB and look it up here.
        foreach ($utxos as &$u) {
            $u['deriv_index'] = $this->findDerivIndexForScript($u['script']) ?? 0;
        }
        unset($u);

        return $this->wallet->buildAndSignTx(
            $xprv,
            $utxos,
            $toAddress,
            $amountSat,
            $feeRateSatPerB,
            $this->hotWalletAddress(),
            $changeIndex ?? 0
        );
    }

    /**
     * Find the HD derivation index that controls a given script.
     * Iterates through derived addresses 0..N and matches by scriptPubKey.
     * Returns null if not found (caller will fall back to 0).
     *
     * Production optimization: persist (address, script, deriv_index) in DB on derivation,
     * then look up here by script hash.
     */
    private function findDerivIndexForScript(string $scriptHex): ?int
    {
        $xpub = $this->xpub();
        // Limit search to 100 addresses max
        for ($i = 0; $i < 100; $i++) {
            try {
                $addr = $this->wallet->deriveAddressFromXpub($xpub, $i);
                // Compute scriptPubKey for this address
                $creator = new \BitWasp\Bitcoin\Address\AddressCreator();
                $addrObj = $creator->fromString($addr, YentenApi::network());
                $myScript = $addrObj->getScriptPubKey()->getHex();
                if (strtolower($myScript) === strtolower($scriptHex)) {
                    return $i;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return null;
    }

    public function broadcast(string $hex): string
    {
        return $this->api()->broadcast($hex);
    }

    public function getBalanceSat(string $address): int
    {
        $r = $this->api()->getBalance($address);
        return (int)($r['balance'] ?? 0);
    }

    public function getUtxos(string $address): array
    {
        return $this->api()->getUnspent($address);
    }

    public function getConfirmations(int $txHeight): int
    {
        if ($txHeight <= 0) return 0;
        $cur = $this->api()->getCurrentHeight();
        return max(0, $cur - $txHeight + 1);
    }
}

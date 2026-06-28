<?php
declare(strict_types=1);

namespace App\Wallet;

use App\Api\SugarchainApi;
use App\Core\Config;

final class SugarchainAdapter implements CoinAdapter
{
    private HdWallet $wallet;

    public function __construct()
    {
        $this->wallet = new HdWallet(SugarchainApi::network());
    }

    public function symbol(): string { return 'SUGAR'; }
    public function name(): string { return 'Sugarchain'; }
    public function decimals(): int { return 8; }
    public function api(): \App\Api\ApiClient { return SugarchainApi::client(); }
    public function hdWallet(): HdWallet { return $this->wallet; }
    public function confirmationsRequired(): int { return SugarchainApi::confirmationsRequired(); }
    public function xpub(): string { return Config::get('SUGAR_XPUB', ''); }
    public function xprv(): string { return Config::get('SUGAR_XPRV', ''); }
    public function hotWalletAddress(): string { return Config::get('SUGAR_HOT_ADDRESS', ''); }
    public function hdBasePath(): string { return Config::get('SUGAR_HD_PATH', "m/44'/408'/0'/0/"); }

    public function deriveDepositAddress(int $index): string
    {
        $xpub = $this->xpub();
        if (empty($xpub) || strpos($xpub, 'your-') !== false) {
            throw new \RuntimeException('SUGAR_XPUB not configured. Run: php bin/generate-wallet.php sugarchain');
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
            throw new \RuntimeException('SUGAR_XPRV not configured');
        }

        $utxos = $this->api()->getUnspent($this->hotWalletAddress());
        if (empty($utxos)) {
            throw new \RuntimeException('No UTXOs in hot wallet. Sweep funds into the hot wallet address first.');
        }

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

    private function findDerivIndexForScript(string $scriptHex): ?int
    {
        $xpub = $this->xpub();
        for ($i = 0; $i < 100; $i++) {
            try {
                $addr = $this->wallet->deriveAddressFromXpub($xpub, $i);
                $creator = new \BitWasp\Bitcoin\Address\AddressCreator();
                $addrObj = $creator->fromString($addr, SugarchainApi::network());
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

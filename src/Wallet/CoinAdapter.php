<?php
declare(strict_types=1);

namespace App\Wallet;

use App\Api\ApiClient;

/**
 * Coin adapter - unifies the API client + HD wallet + network params per coin.
 */
interface CoinAdapter
{
    public function symbol(): string;
    public function name(): string;
    public function decimals(): int;
    public function api(): ApiClient;
    public function hdWallet(): HdWallet;
    public function confirmationsRequired(): int;
    public function xpub(): string;
    public function xprv(): string;
    public function hotWalletAddress(): string;
    public function hdBasePath(): string;

    /** Derive next deposit address (uses xpub only, safe in web process). */
    public function deriveDepositAddress(int $index): string;

    /** Build + sign payout tx (uses xprv, MUST be called from worker process only). */
    public function buildPayoutTx(
        string $toAddress,
        int $amountSat,
        int $feeRateSatPerB,
        ?int $changeIndex = null
    ): string;

    /** Broadcast signed raw tx. */
    public function broadcast(string $hex): string;

    /** Get current balance of an address in satoshis. */
    public function getBalanceSat(string $address): int;

    /** Get UTXOs of an address. */
    public function getUtxos(string $address): array;

    /** Get confirmations of a tx (height-based). */
    public function getConfirmations(int $txHeight): int;
}

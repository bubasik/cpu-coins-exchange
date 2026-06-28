<?php
declare(strict_types=1);

namespace App\Network;

use BitWasp\Bitcoin\Network\Network;

/**
 * Sugarchain (SUGAR) mainnet - fork of Bitcoin Core 0.16+
 * Source: github.com/sugarchain-project/sugarchain/blob/master/src/chainparams.cpp
 *
 * base58Prefixes[PUBKEY_ADDRESS] = 63      (0x3F)  -> 'S...'
 * base58Prefixes[SCRIPT_ADDRESS] = 125     (0x7D)
 * base58Prefixes[SECRET_KEY]     = 128     (0x80)
 * base58Prefixes[EXT_PUBLIC_KEY] = {0x04,0x88,0xB2,0x1E}
 * base58Prefixes[EXT_SECRET_KEY] = {0x04,0x88,0xAD,0xE4}
 * bech32_hrp = "sugar"
 * nPowTargetSpacing = 5 (5 sec blocks, DigiShieldZEC)
 */
final class SugarchainNetwork extends Network
{
    protected $base58PrefixMap = [
        self::BASE58_ADDRESS_P2PKH => '3f',
        self::BASE58_ADDRESS_P2SH  => '7d',
        self::BASE58_WIF           => '80',
    ];

    protected $bip32PrefixMap = [
        self::BIP32_PREFIX_XPUB => '0488b21e',
        self::BIP32_PREFIX_XPRV => '0488ade4',
    ];

    protected $bip32ScriptTypeMap = [
        self::BIP32_PREFIX_XPUB => 'p2pkh',
        self::BIP32_PREFIX_XPRV => 'p2pkh',
    ];

    protected $bech32PrefixMap = [
        self::BECH32_PREFIX_SEGWIT => 'sugar',
    ];

    protected $signedMessagePrefix = 'Sugarchain Signed Message:\n';

    protected $p2pMagic = 'f0c78ef5';

    public function getNetCode(): string { return 'sugarchain'; }
}

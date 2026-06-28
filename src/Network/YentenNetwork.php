<?php
declare(strict_types=1);

namespace App\Network;

use BitWasp\Bitcoin\Network\Network;

/**
 * Yenten (YTN) mainnet - yenten-6 fork of Bitcoin Core 0.16+
 * Source: github.com/yentencoin/yenten/blob/yenten-6/src/chainparams.cpp
 *
 * base58Prefixes[PUBKEY_ADDRESS] = 78      (0x4E)  -> 'Y...'
 * base58Prefixes[SCRIPT_ADDRESS] = 10      (0x0A)
 * base58Prefixes[SECRET_KEY]     = 123     (0x7B)
 * base58Prefixes[EXT_PUBLIC_KEY] = {0x04,0x88,0xB2,0x1E}
 * base58Prefixes[EXT_SECRET_KEY] = {0x04,0x88,0xAD,0xE4}
 * bech32_hrp = "ytn"
 * nPowTargetSpacing = 120 (2 min blocks)
 */
final class YentenNetwork extends Network
{
    protected $base58PrefixMap = [
        self::BASE58_ADDRESS_P2PKH => '4e',
        self::BASE58_ADDRESS_P2SH  => '0a',
        self::BASE58_WIF           => '7b',
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
        self::BECH32_PREFIX_SEGWIT => 'ytn',
    ];

    protected $signedMessagePrefix = 'Yenten Signed Message:\n';

    protected $p2pMagic = 'ad5aeb9f';

    public function getNetCode(): string { return 'yenten'; }
}

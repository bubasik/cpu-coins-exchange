<?php
declare(strict_types=1);

namespace App\Network;

use BitWasp\Bitcoin\Network\Network;
use BitWasp\Bitcoin\Script\ScriptType;

/**
 * Adventurecoin (ADVC) mainnet
 * Source: provided by user (BitWasp\Bitcoin\Network\Networks\Adventurecoin)
 *
 * base58Prefixes[PUBKEY_ADDRESS] = 23       (0x17)  -> 'A...'
 * base58Prefixes[SCRIPT_ADDRESS] = 10       (0x0A)
 * base58Prefixes[SECRET_KEY]     = 123      (0x7B)
 * bech32_hrp = "advc"
 * HD prefixes = standard Bitcoin (xprv/xpub)
 *
 * API: https://api2.adventurecoin.quest
 * Block time: ~120 seconds (similar to Yenten)
 */
final class AdventurecoinNetwork extends Network
{
    protected $base58PrefixMap = [
        self::BASE58_ADDRESS_P2PKH => '17',
        self::BASE58_ADDRESS_P2SH  => '0a',
        self::BASE58_WIF           => '7b',
    ];

    protected $bip32PrefixMap = [
        self::BIP32_PREFIX_XPUB => '0488b21e',
        self::BIP32_PREFIX_XPRV => '0488ade4',
    ];

    protected $bip32ScriptTypeMap = [
        self::BIP32_PREFIX_XPUB => ScriptType::P2PKH,
        self::BIP32_PREFIX_XPRV => ScriptType::P2PKH,
    ];

    protected $bech32PrefixMap = [
        self::BECH32_PREFIX_SEGWIT => 'advc',
    ];

    protected $signedMessagePrefix = 'AdventureCoin Signed Message';

    protected $p2pMagic = 'dbb6c0fb';

    public function getNetCode(): string { return 'adventurecoin'; }
}

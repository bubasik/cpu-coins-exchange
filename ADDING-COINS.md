# Adding a New Coin

This guide walks through adding a new cryptocurrency to the Yenten-Sugar Exchange platform. The platform uses Bitcoin-compatible UTXO model, so any coin based on Bitcoin Core 0.16+ with a public `sugarchain-project/api-server` HTTP API works.

## Prerequisites

You need to know for the new coin:

1. **API base URL** (e.g. `https://api.example.com`)
2. **Chain parameters** (from the coin's `src/chainparams.cpp`):
   - `base58Prefixes[PUBKEY_ADDRESS]` — P2PKH prefix (decimal or hex)
   - `base58Prefixes[SCRIPT_ADDRESS]` — P2SH prefix
   - `base58Prefixes[SECRET_KEY]` — WIF prefix
   - `bech32_hrp` — Bech32 Human-Readable Part (or `null` if no segwit)
   - `base58Prefixes[EXT_PUBLIC_KEY]` — 4-byte xpub prefix (usually `0488b21e` = Bitcoin standard)
   - `base58Prefixes[EXT_SECRET_KEY]` — 4-byte xprv prefix (usually `0488ade4` = Bitcoin standard)
3. **Block spacing** (from `consensus.nPowTargetSpacing`) — for setting confirmation requirements
4. **Symbol** (e.g. `YTN`, `SUGAR`, `ADVC`) — 3–5 chars, uppercase
5. **Display name** (e.g. `Yenten`, `Sugarchain`, `Adventurecoin`)

## Step-by-step

### Step 1: Find the chainparams

Look at the coin's source code on GitHub. The file is at `src/chainparams.cpp` (Bitcoin Core 0.16+) or `src/chainparams.cpp` (older).

Example — Yenten-6 (from `yentencoin/yenten:yenten-6/src/chainparams.cpp`):

```cpp
base58Prefixes[PUBKEY_ADDRESS] = std::vector<unsigned char>(1,78);  // 0x4E -> 'Y...'
base58Prefixes[SCRIPT_ADDRESS] = std::vector<unsigned char>(1,10);  // 0x0A
base58Prefixes[SECRET_KEY]     = std::vector<unsigned char>(1,123); // 0x7B
base58Prefixes[EXT_PUBLIC_KEY] = {0x04, 0x88, 0xB2, 0x1E};
base58Prefixes[EXT_SECRET_KEY] = {0x04, 0x88, 0xAD, 0xE4};
bech32_hrp = "ytn";
consensus.nPowTargetSpacing = 120;  // 2 minutes
```

Convert each prefix to a 2-char hex string:
- `78` → `"4e"`
- `10` → `"0a"`
- `123` → `"7b"`

For 4-byte prefixes, concatenate the bytes: `{0x04, 0x88, 0xB2, 0x1E}` → `"0488b21e"`.

### Step 2: Create the Network class

Create `src/Network/{CoinName}Network.php`. Use this template (replace `{CoinName}`, the hex prefixes, and the bech32 HRP):

```php
<?php
declare(strict_types=1);

namespace App\Network;

use BitWasp\Bitcoin\Network\Network;
use BitWasp\Bitcoin\Script\ScriptType;

/**
 * {CoinName} ({SYMBOL}) mainnet
 * Source: <link to chainparams.cpp>
 *
 * base58Prefixes[PUBKEY_ADDRESS] = {dec}      (0x{hex})  -> '{first_char}...'
 * base58Prefixes[SCRIPT_ADDRESS] = {dec}      (0x{hex})
 * base58Prefixes[SECRET_KEY]     = {dec}      (0x{hex})
 * bech32_hrp = "{hrp}"
 * HD prefixes = standard Bitcoin (xprv/xpub)
 */
final class {CoinName}Network extends Network
{
    protected $base58PrefixMap = [
        self::BASE58_ADDRESS_P2PKH => '{p2pkh_hex}',
        self::BASE58_ADDRESS_P2SH  => '{p2sh_hex}',
        self::BASE58_WIF           => '{wif_hex}',
    ];

    protected $bip32PrefixMap = [
        self::BIP32_PREFIX_XPUB => '0488b21e',
        self::BIP32_PREFIX_XPRV => '0488ade4',
    ];

    protected $bip32ScriptTypeMap = [
        self::BIP32_PREFIX_XPUB => ScriptType::P2PKH,
        self::BIP32_PREFIX_XPRV => ScriptType::P2PKH,
    ];

    // Remove this block if the coin has no segwit (no bech32_hrp in chainparams)
    protected $bech32PrefixMap = [
        self::BECH32_PREFIX_SEGWIT => '{hrp}',
    ];

    protected $signedMessagePrefix = '{CoinName} Signed Message';

    protected $p2pMagic = '{p2p_magic_hex}';  // from pchMessageStart

    public function getNetCode(): string { return strtolower('{coinname}'); }
}
```

**Real example** — `src/Network/AdventurecoinNetwork.php`:

```php
<?php
declare(strict_types=1);

namespace App\Network;

use BitWasp\Bitcoin\Network\Network;
use BitWasp\Bitcoin\Script\ScriptType;

final class AdventurecoinNetwork extends Network
{
    protected $base58PrefixMap = [
        self::BASE58_ADDRESS_P2PKH => '17',  // 0x17 = 23 -> 'A...'
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
```

### Step 3: Create the API class

Create `src/Api/{CoinName}Api.php`. This is a thin wrapper that points to the coin's API URL and confirms the network class:

```php
<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Config;
use App\Network\{CoinName}Network;

final class {CoinName}Api
{
    private static ?ApiClient $client = null;
    private static ?{CoinName}Network $network = null;

    public static function client(): ApiClient
    {
        if (self::$client === null) {
            // trim() twice to remove any whitespace/CRLF from .env
            $url = trim(Config::get('{SYMBOL}_API', 'https://api.example.com'));
            $url = rtrim($url, '/');
            self::$client = new ApiClient($url, 15, 8);
        }
        return self::$client;
    }

    public static function network(): {CoinName}Network
    {
        if (self::$network === null) self::$network = new {CoinName}Network();
        return self::$network;
    }

    public static function confirmationsRequired(): int
    {
        return (int)Config::get('{SYMBOL}_CONFATIONS', 6);
    }

    public static function decimals(): int { return 8; }
    public static function symbol(): string { return '{SYMBOL}'; }
    public static function name(): string { return '{CoinName}'; }
}
```

**Real example** — `src/Api/AdventurecoinApi.php`:

```php
<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Config;
use App\Network\AdventurecoinNetwork;

final class AdventurecoinApi
{
    private static ?ApiClient $client = null;
    private static ?AdventurecoinNetwork $network = null;

    public static function client(): ApiClient
    {
        if (self::$client === null) {
            $url = trim(Config::get('ADVC_API', 'https://api2.adventurecoin.quest'));
            $url = rtrim($url, '/');
            self::$client = new ApiClient($url, 15, 8);
        }
        return self::$client;
    }

    public static function network(): AdventurecoinNetwork
    {
        if (self::$network === null) self::$network = new AdventurecoinNetwork();
        return self::$network;
    }

    public static function confirmationsRequired(): int
    {
        return (int)Config::get('ADVC_CONFIRMATIONS', 6);
    }

    public static function decimals(): int { return 8; }
    public static function symbol(): string { return 'ADVC'; }
    public static function name(): string { return 'Adventurecoin'; }
}
```

### Step 4: Create the Adapter

Create `src/Wallet/{CoinName}Adapter.php`. This implements the `CoinAdapter` interface (deposit address derivation, payout tx building, balance/UTXO queries):

```php
<?php
declare(strict_types=1);

namespace App\Wallet;

use App\Api\{CoinName}Api;
use App\Core\Config;

final class {CoinName}Adapter implements CoinAdapter
{
    private HdWallet $wallet;

    public function __construct()
    {
        $this->wallet = new HdWallet({CoinName}Api::network());
    }

    public function symbol(): string { return '{SYMBOL}'; }
    public function name(): string { return '{CoinName}'; }
    public function decimals(): int { return 8; }
    public function api(): \App\Api\ApiClient { return {CoinName}Api::client(); }
    public function hdWallet(): HdWallet { return $this->wallet; }
    public function confirmationsRequired(): int { return {CoinName}Api::confirmationsRequired(); }
    public function xpub(): string { return Config::get('{SYMBOL}_XPUB', ''); }
    public function xprv(): string { return Config::get('{SYMBOL}_XPRV', ''); }
    public function hotWalletAddress(): string { return Config::get('{SYMBOL}_HOT_ADDRESS', ''); }
    public function hdBasePath(): string { return Config::get('{SYMBOL}_HD_PATH', "m/44'/1234'/0'/0/"); }

    public function deriveDepositAddress(int $index): string
    {
        $xpub = $this->xpub();
        if (empty($xpub) || strpos($xpub, 'your-') !== false) {
            throw new \RuntimeException('{SYMBOL}_XPUB not configured. Run: php bin/generate-wallet.php {coinname}');
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
            throw new \RuntimeException('{SYMBOL}_XPRV not configured');
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
                $addrObj = $creator->fromString($addr, {CoinName}Api::network());
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
```

Just copy `src/Wallet/AdventurecoinAdapter.php` and replace:
- `Adventurecoin` → `{CoinName}` (everywhere)
- `ADVC` → `{SYMBOL}` (uppercase)
- `adventurecoin` → `{coinname}` (lowercase, in error messages)

### Step 5: Register in AdapterRegistry

Edit `src/Wallet/AdapterRegistry.php`:

```php
return match ($coin) {
    'YTN', 'YENTEN', 'YENTENCOIN'   => self::$adapters['YTN']   = new YentenAdapter(),
    'SUGAR', 'SUGARCHAIN'           => self::$adapters['SUGAR'] = new SugarchainAdapter(),
    'ADVC', 'ADVENTURECOIN', 'ADVENTURE' => self::$adapters['ADVC'] = new AdventurecoinAdapter(),
    'NEW', 'NEWCOIN'                => self::$adapters['NEW']   = new NewcoinAdapter(),  // <-- ADD THIS
    default => throw new \InvalidArgumentException("Unknown coin: $coin"),
};
```

Also update `listForUi()` and `all()`:

```php
public static function all(): array
{
    return [
        'YTN'   => self::get('YTN'),
        'SUGAR' => self::get('SUGAR'),
        'ADVC'  => self::get('ADVC'),
        'NEW'   => self::get('NEW'),  // <-- ADD
    ];
}

public static function listForUi(): array
{
    return [
        'YTN'   => 'Yenten (YTN)',
        'SUGAR' => 'Sugarchain (SUGAR)',
        'ADVC'  => 'Adventurecoin (ADVC)',
        'NEW'   => 'Newcoin (NEW)',  // <-- ADD
    ];
}
```

### Step 6: Add env vars

Edit `.env.example` and your local `.env`:

```bash
# ============ Newcoin (NEW) ============
NEW_API=https://api.example.com
NEW_XPRV=xprv...your-new-master-private-key...
NEW_XPUB=xpub...your-new-master-public-key...
NEW_HOT_ADDRESS=N...
NEW_CONFIRMATIONS=6
NEW_HD_PATH=m/44H/1234H/0H/0/
```

**Note on HD path**: Use the coin's BIP44 coin type if registered at https://github.com/satoshilabs/slips/blob/master/slip-0044.md. Otherwise use a placeholder like `1234` and remember it's not standard.

### Step 7: Update controllers

Edit `src/Controller/PageController.php` — add the coin to all hardcoded lists:

```php
// In home() method
$coins = ['YTN' => 'ytnInfo', 'SUGAR' => 'sugarInfo', 'ADVC' => 'advcInfo', 'NEW' => 'newInfo'];

// In dashboard() method
foreach (['YTN', 'SUGAR', 'ADVC', 'NEW'] as $coin) {
    // ...
}
```

Edit `src/Controller/AuthController.php` — initialize wallet at registration:

```php
foreach (['YTN', 'SUGAR', 'ADVC', 'NEW'] as $coin) {
    $pdo->prepare('INSERT OR IGNORE INTO user_wallets (user_id, coin, balance, locked) VALUES (?, ?, 0, 0)')
        ->execute([$userId, $coin]);
}
```

Edit `src/Service/ExchangeService.php` — add to supported coins list:

```php
$supportedCoins = ['YTN', 'SUGAR', 'ADVC', 'NEW'];
```

Add a reference exchange rate (vs YTN) and let the bridge compute the rest:

```php
$ref = [
    'YTN-SUGAR'   => (float)Config::get('RATE_YTN_TO_SUGAR', '100.0'),
    'YTN-ADVC'    => (float)Config::get('RATE_YTN_TO_ADVC', '1.0'),
    'YTN-NEW'     => (float)Config::get('RATE_YTN_TO_NEW', '1.0'),  // <-- ADD
];

// And in rateToYtn() / rateFromYtn():
'NEW' => 1.0 / $ref['YTN-NEW'],   // in rateToYtn
'NEW' => $ref['YTN-NEW'],         // in rateFromYtn
```

### Step 8: Update frontend

#### 8.1 Exchange page dropdowns

Edit `public/views/exchange.php` — add `<option>` to both `<select>`:

```html
<select class="input-suffix" id="from-coin">
    <option value="YTN">YTN</option>
    <option value="SUGAR">SUGAR</option>
    <option value="ADVC">ADVC</option>
    <option value="NEW">NEW</option>  <!-- ADD -->
</select>
```

#### 8.2 Dashboard deposit/withdraw sections

Edit `public/views/dashboard.php` — replace hardcoded `['YTN', 'SUGAR', 'ADVC']` arrays with `['YTN', 'SUGAR', 'ADVC', 'NEW']`:

```php
<?php foreach (['YTN', 'SUGAR', 'ADVC', 'NEW'] as $coin): ?>
```

There are 3 such `foreach` loops in `dashboard.php` (balances table, deposit addresses, withdraw forms).

#### 8.3 Home page stats (optional)

Edit `public/views/home.php` — copy the ADVC stat-grid block and replace the variable name:

```php
$advc = $data['advcInfo'] ?? [];
$new = $data['newInfo'] ?? [];  // <-- ADD at top
```

```html
<!-- ADD a new stat-grid block -->
<div class="stat-grid">
    <div class="stat">
        <div class="stat-label">Newcoin (NEW)</div>
        <div class="stat-value"><?= number_format((int)($new['blocks'] ?? 0)) ?></div>
        <div class="stat-sub" data-i18n="home.stats.blocks">Blocks</div>
    </div>
    <!-- ... copy other 3 stats, replace $new -->
</div>
```

### Step 9: Update CLI scripts

Edit `bin/generate-wallet.php`:

```php
if (!in_array($coin, ['yenten', 'sugarchain', 'adventurecoin', 'newcoin'])) {  // <-- ADD 'newcoin'
    fwrite(STDERR, "Usage: php bin/generate-wallet.php <yenten|sugarchain|adventurecoin|newcoin>\n");
    exit(1);
}

$network = match ($coin) {
    'yenten'        => new YentenNetwork(),
    'sugarchain'    => new SugarchainNetwork(),
    'adventurecoin' => new AdventurecoinNetwork(),
    'newcoin'       => new NewcoinNetwork(),  // <-- ADD
};
```

Edit `bin/test-build-tx.php` — same changes.

Edit `bin/debug-api.php` — same changes, plus add to the `$coinSymbol` match:

```php
$coinSymbol = match ($coin) {
    'yenten'        => 'YTN',
    'sugarchain'    => 'SUGAR',
    'adventurecoin' => 'ADVC',
    'newcoin'       => 'NEW',  // <-- ADD
};
```

And in the API URL match:

```php
$apiUrl = match ($coinSymbol) {
    'YTN'   => Config::get('YTN_API', 'https://api.yentencoin.info'),
    'SUGAR' => Config::get('SUGAR_API', 'https://api.sugarchain.org'),
    'ADVC'  => Config::get('ADVC_API', 'https://api2.adventurecoin.quest'),
    'NEW'   => Config::get('NEW_API', 'https://api.example.com'),  // <-- ADD
};
```

### Step 10: Test

#### 10.1 Generate HD keys

```bash
php bin/generate-wallet.php newcoin
# Copy xprv, xpub, first address into .env as NEW_XPRV, NEW_XPUB, NEW_HOT_ADDRESS
```

**Verify**: The first address must start with the coin's P2PKH prefix character (e.g. `N` for our hypothetical Newcoin). If it doesn't, your chainparams are wrong — recheck Step 1.

#### 10.2 Test transaction building

```bash
php bin/test-build-tx.php newcoin
```

Expected output ends with:
```
=== Test PASSED: transaction was built and signed successfully ===
```

If the test passes, the API accepted your signed transaction as well-formed. If `/decode` fails with "TX decode failed", check the network prefix bytes again.

#### 10.3 Test API connectivity

```bash
php bin/debug-api.php newcoin <address>
# Or with no address — picks first pending swap from DB
php bin/debug-api.php newcoin
```

Should show:
- ✅ DNS resolution
- ✅ HTTP 200 on `/info`
- ✅ Raw curl returns `{"balance":...}` (not "Invalid address")
- ✅ ApiClient wrapper returns balance

#### 10.4 Test live swap

Start the server, create a swap with `from_coin=NEW` and verify the deposit address starts with the right prefix:

```bash
php -S 0.0.0.0:8080 -t public/

# In another terminal
curl -X POST http://localhost:8080/api/exchange/create \
  -H "Content-Type: application/json" \
  -H "X-Requested-With: XMLHttpRequest" \
  -d '{"from_coin":"NEW","to_coin":"YTN","from_amount":"1","payout_address":"<a valid YTN address>"}'
```

The response should include `deposit_address` starting with the coin's prefix character.

## Verification checklist

After adding a new coin, verify:

- [ ] `php bin/generate-wallet.php newcoin` generates xprv/xpub/first_address
- [ ] First address starts with the correct prefix character (e.g. `N` for our example)
- [ ] `php bin/test-build-tx.php newcoin` ends with "Test PASSED"
- [ ] `php bin/debug-api.php newcoin <addr>` shows all ✅
- [ ] Direct curl `https://api.example.com/balance/<addr>` returns `{"balance":...}` (not "Invalid address")
- [ ] Exchange page (`/exchange`) shows NEW in both dropdowns
- [ ] Creating a swap with `from_coin=NEW` returns a deposit_address starting with the correct prefix
- [ ] Dashboard (`/dashboard`) shows NEW in balances, deposit, and withdraw sections
- [ ] Home page shows NEW network stats (if you added the stat-grid block)
- [ ] Estimate API works for all 6+ pairs involving the new coin

## Common pitfalls

### 1. Address starts with wrong character

**Symptom**: Generated address doesn't start with the expected character (e.g. `Y` for Yenten but you get `S`).

**Cause**: Wrong hex prefix in `base58PrefixMap`. Decimal → hex conversion was wrong.

**Fix**: `78` decimal = `0x4E` = `"4e"` hex string. Double-check with:
```bash
php -r "echo dechex(78);"
# Output: 4e
```

### 2. API returns "Invalid address"

**Symptom**: `curl https://api.example.com/balance/<addr>` returns `{"error":{"code":-5,"message":"Invalid address"}}`.

**Cause**: Either (a) wrong prefix in Network class, or (b) old addresses in DB from previous xprv.

**Fix**:
1. Run `php bin/test-build-tx.php newcoin` — if it fails, prefix is wrong (Step 1)
2. If test passes but API still rejects, clear old DB records:
   ```bash
   php bin/clear-old-orders.php --force
   ```

### 3. "Call to undefined function gmp_init()"

**Cause**: PHP `gmp` extension not installed.

**Fix** (Debian/Ubuntu): `sudo apt-get install php8.2-gmp`

**Fix** (Docker): Already installed in Dockerfile. Rebuild: `docker compose build --no-cache php`

### 4. "Class BitWasp\Bitcoin\Buffer not found"

**Cause**: Wrong class name. The Buffer class is at `BitWasp\Buffertools\Buffer`, not `BitWasp\Bitcoin\Buffer`.

**Fix**: Use the correct import:
```php
use BitWasp\Buffertools\Buffer;
```

### 5. Address derived from wrong network

**Symptom**: All generated addresses start with `S` (Sugarchain prefix) even for Yenten or ADVC.

**Cause**: `Bitcoin::setNetwork()` is called somewhere, which is **global static state**. When two adapters are created in the same process, the second overwrites the first.

**Fix**: Never call `Bitcoin::setNetwork()`. Always pass the `$network` argument explicitly to bitcoin-php functions. The current `HdWallet` class already does this correctly — don't add `Bitcoin::setNetwork()` back.

### 6. buildAndSignTx fails with "SplFixedArray::rewind()"

**Cause**: PHP 8.4 removed `SplFixedArray::rewind()`, but bitcoin-php's `Signer::get()` uses it.

**Fix**: The current `HdWallet::buildAndSignTx()` works around this by rebuilding the tx manually with `TxBuilder`. Don't switch back to `Signer::get()`.

## Summary: files to create/edit

**Create** (3 files):
- `src/Network/{CoinName}Network.php`
- `src/Api/{CoinName}Api.php`
- `src/Wallet/{CoinName}Adapter.php`

**Edit** (8 files):
- `src/Wallet/AdapterRegistry.php` — add to `get()`, `all()`, `listForUi()`
- `src/Controller/PageController.php` — add to `home()` and `dashboard()` loops
- `src/Controller/AuthController.php` — add to wallet initialization loop
- `src/Service/ExchangeService.php` — add to `$supportedCoins`, `$ref`, `rateToYtn()`, `rateFromYtn()`
- `.env.example` and `.env` — add 6 env vars
- `public/views/exchange.php` — add `<option>` to 2 dropdowns
- `public/views/dashboard.php` — replace 3 `['YTN', 'SUGAR', 'ADVC']` with the new list
- `public/views/home.php` — (optional) add stat-grid block

**Edit** (3 CLI scripts):
- `bin/generate-wallet.php`
- `bin/test-build-tx.php`
- `bin/debug-api.php`

Total: 3 new files + 11 edits. About 30 minutes of work for a new coin.

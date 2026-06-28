# Yenten ↔ Sugarchain Exchange & Trading Platform

PHP 8.2 platform with two sections (Instant Exchange + Limit/Market Trading) for the YTN/SUGAR pair, using official public APIs only (no full nodes required).

## Two run modes

| Mode | Description | Best for |
|---|---|---|
| **A. Pure PHP** | `php -S` built-in server + workers + cron | Development, simple VPS deployment |
| **B. Docker Compose** | 5 containers (PHP + Redis + 3 workers) | Production, easy scaling, isolated environment |

Both modes use **exactly the same PHP code**. Mode B just wraps Mode A in Docker containers.

## Features

- **Instant Exchange** — swap YTN ↔ SUGAR in seconds with on-chain settlement
- **Trading** — limit and market orders, real-time order book, candlestick chart, trade history
- **4 languages** — English, Russian, Japanese, Chinese (with runtime switcher)
- **2 themes** — Light and Dark (CSS variables, persisted per user)
- **Email + password auth** — bcrypt, 7-day signed session cookies
- **API-only** — uses `api.yentencoin.info` and `api.sugarchain.org` for all chain operations
- **bitcoin-php** — local transaction building & signing (private keys never leave the server)
- **Working `buildAndSignTx`** — fully functional P2PKH transaction building & signing via `bitwasp/bitcoin`

## Stack

- PHP 8.2 (no framework)
- Composer: `bitwasp/bitcoin` (dev-master, PHP 8+ compatible), `predis/predis`, `phpmailer/phpmailer`
- SQLite for persistence (`storage/exchange.sqlite`)
- Redis (optional — has file-based fallback for development without Redis)
- Vanilla JS + Canvas chart (no frontend dependencies)

## Coin configuration

Three coins supported, all using the same `sugarchain-project/api-server` HTTP API. Network parameters (verified from sources):

| Param | Yenten (YTN) | Sugarchain (SUGAR) | Adventurecoin (ADVC) |
|---|---|---|---|
| Source | `yentencoin/yenten:yenten-6/src/chainparams.cpp` | `sugarchain-project/sugarchain:master/src/chainparams.cpp` | provided by user (BitWasp Network class) |
| API base | `https://api.yentencoin.info` | `https://api.sugarchain.org` | `https://api2.adventurecoin.quest` |
| P2PKH prefix | 78 (0x4E) → `Y...` | 63 (0x3F) → `S...` | 23 (0x17) → `A...` |
| P2SH prefix | 10 (0x0A) | 125 (0x7D) | 10 (0x0A) |
| WIF prefix | 123 (0x7B) | 128 (0x80) | 123 (0x7B) |
| Bech32 HRP | `ytn` | `sugar` | `advc` |
| Block spacing | 120 sec | 5 sec | ~120 sec |
| Deposit confs | 6 (~12 min) | 30 (~2.5 min) | 6 (~12 min) |

All three support P2PKH (legacy addresses) and Bech32 (segwit) — transactions are built using `buildAndSignTx` in `App\Wallet\HdWallet`.

## Installation

### Mode A: Pure PHP (development / simple VPS)

#### 1. Install PHP 8.2+ with extensions

Required extensions: `pdo_sqlite`, `curl`, `mbstring`, `bcmath`, `gmp`, `xml`.

Debian/Ubuntu:
```bash
sudo apt-get install -y php8.2-cli php8.2-sqlite3 php8.2-mbstring php8.2-curl \
  php8.2-bcmath php8.2-gmp php8.2-xml php8.2-zip redis-server
```

#### 2. Clone & install dependencies

```bash
git clone <your-repo-url> yenten-sugar
cd yenten-sugar
composer install
```

#### 3. Configure environment

```bash
cp .env.example .env
# Generate app secret
sed -i "s/change-me-to-64-random-hex-chars/$(openssl rand -hex 32)/" .env
# Edit .env with your favorite editor to fill in:
#   - YTN_XPUB, YTN_XPRV, YTN_HOT_ADDRESS
#   - SUGAR_XPUB, SUGAR_XPRV, SUGAR_HOT_ADDRESS
#   - REDIS_HOST, REDIS_PORT
#   - MAIL_HOST, MAIL_USER, MAIL_PASSWORD
```

#### 4. Generate HD wallet keys

```bash
php bin/generate-wallet.php yenten
# Copy the xprv, xpub, and first address into .env as YTN_XPRV, YTN_XPUB, YTN_HOT_ADDRESS

php bin/generate-wallet.php sugarchain
# Same for SUGAR_XPRV, SUGAR_XPUB, SUGAR_HOT_ADDRESS

php bin/generate-wallet.php adventurecoin
# Same for ADVC_XPRV, ADVC_XPUB, ADVC_HOT_ADDRESS
```

**Important**: Store xprv securely. It controls all derived addresses. Never commit `.env` to git.

#### 5. Initialize database

```bash
php bin/migrate.php
```

#### 6. Test transaction building (optional but recommended)

```bash
php bin/test-build-tx.php yenten
php bin/test-build-tx.php sugarchain
php bin/test-build-tx.php adventurecoin
```

This generates a fresh HD key, builds a sample signed transaction, and verifies it via the public API's `/decode` endpoint. If all three pass with "Test PASSED", your chainparams and signing code are correct.

#### 7. Start the PHP server

```bash
php -S 0.0.0.0:8080 -t public/
```

Visit http://localhost:8080 — you should see the home page with live network stats from both APIs.

#### 8. Start background workers

In 3 separate terminals (or via supervisor/systemd):

```bash
php bin/worker-deposit.php    # polls deposits every 30s
php bin/worker-dispenser.php  # processes pending withdrawals (loads xprv!)
php bin/worker-matcher.php    # matching engine, 1s tick
```

#### 9. Add cron entry

```bash
crontab -e
# Add:
* * * * * cd /path/to/yenten-sugar && php bin/cron-aggregate.php >> storage/logs/cron.log 2>&1
```

### Mode B: Docker Compose (production)

#### 1. Configure environment

```bash
cp .env.example .env
# Edit .env as in Mode A (HD keys, SMTP, etc.)
# Note: REDIS_HOST=redis (the docker service name)
```

#### 2. Start everything

```bash
docker compose up -d --build
```

The `--build` flag rebuilds the PHP image with all extensions pre-installed (pdo_sqlite, bcmath, gmp, redis, etc.). On first start, `composer install` runs automatically inside the `php` container (you'll see `[startup] Installing PHP dependencies...` in the logs). On subsequent starts, it skips composer install because `vendor/` is already on your host filesystem (bind-mounted).

This starts 5 containers:
- `ys-php` on port **8080** (web + API)
- `ys-redis` on port 6379
- `ys-worker-deposit`, `ys-worker-dispenser`, `ys-worker-matcher`

Visit http://localhost:8080.

#### 3. Generate HD keys (inside the container)

```bash
docker compose exec php php bin/generate-wallet.php yenten
docker compose exec php php bin/generate-wallet.php sugarchain
docker compose exec php php bin/generate-wallet.php adventurecoin
# Copy output into .env, then restart:
docker compose restart
```

#### 4. View logs

```bash
docker compose logs -f php
docker compose logs -f worker-deposit worker-dispenser worker-matcher
```

#### 5. Stop

```bash
docker compose down
```

#### Troubleshooting Docker

**"Failed to open vendor/autoload.php"**: This happens if the `php` container started before `composer install` finished. Fix:
```bash
docker compose down
docker compose up -d --build
docker compose logs -f php    # wait for "Starting PHP server" message
```

If still broken, install manually:
```bash
docker compose exec php composer install --no-interaction --optimize-autoloader --ignore-platform-reqs
docker compose restart php
```

**Extensions missing** (e.g. "Call to undefined function gmp_init"): The Dockerfile installs all required extensions at build time. If you modified the Dockerfile, rebuild:
```bash
docker compose build --no-cache php
docker compose up -d
```

## Usage

### Instant Exchange flow

1. User visits `/exchange`
2. Selects `from_coin`, `to_coin`, enters amount + payout address
3. Server creates a `swap_orders` row and returns a deposit address (HD-derived)
4. User sends funds to that address
5. `worker-deposit.php` polls `/balance/{addr}` every 30s, detects incoming tx
6. After 6 (YTN) or 30 (SUGAR) confirmations, marks as `confirmed`
7. `worker-dispenser.php` builds + signs payout tx via `bitcoin-php`, broadcasts via API
8. User receives funds at the payout address

### Trading flow

1. User logs in, deposits YTN or SUGAR via dashboard (HD-derived address per user)
2. Same watcher credits `user_wallets.balance` after confirmations
3. User places limit/market order on `/trade`
4. Required balance is locked in `user_wallets.locked`
5. `worker-matcher.php` matches orders every 1 second
6. On match: balances moved between users, trade recorded, candlestick aggregated
7. User can withdraw via dashboard → `worker-dispenser.php` broadcasts payout

## Architecture

```
┌─────────────────────────────────────────────────────┐
│ Web (PHP-FPM or built-in server)                    │
│ public/index.php                                    │
│ - Renders pages, handles auth, accepts orders       │
│ - Uses xpub only (NEVER loads xprv)                 │
└─────────────────────────────────────────────────────┘
                  │                       │
                  ▼                       ▼
        ┌──────────────┐         ┌──────────────┐
        │  SQLite      │         │   Redis      │
        │  users,      │         │  sessions,   │
        │  orders,     │         │  orderbook,  │
        │  trades,     │         │  rate limits │
        │  wallets     │         └──────────────┘
        └──────────────┘
                  ▲ (fallback: file-based cache when Redis unavailable)

┌─────────────────────────────────────────────────────┐
│ Workers (long-running PHP CLI processes)             │
│                                                      │
│ worker-deposit.php    — polls /balance every 30s     │
│ worker-dispenser.php  — builds+signs+broadcasts      │
│   ⚠ loads xprv (master private key)                  │
│   ⚠ must run as isolated user, no web access         │
│ worker-matcher.php    — order matching every 1s      │
└─────────────────────────────────────────────────────┘
                  │
                  ▼
        ┌──────────────────────┐
        │  Public APIs         │
        │  api.yentencoin.info │
        │  api.sugarchain.org  │
        └──────────────────────┘
```

## Transaction building (`buildAndSignTx`)

The `App\Wallet\HdWallet::buildAndSignTx()` method:

1. Takes UTXOs (from `/api/unspent`), a destination address, amount, fee rate, and change address
2. Selects UTXOs greedily until enough to cover amount + estimated fee
3. Builds an unsigned transaction via `TransactionFactory::build()`
4. For each input, derives the private key from `xprv` at the corresponding HD index
5. Signs each input using `InputSigner` with `SigHash::ALL`
6. Applies the scriptSig back to the transaction (avoids `Signer::get()` which is incompatible with PHP 8.4 due to `SplFixedArray::rewind()` removal)
7. Returns the signed raw tx hex

Test it:
```bash
php bin/test-build-tx.php yenten
php bin/test-build-tx.php sugarchain
```

Sample output (truncated):
```
Hot wallet address (m/0/0): YgMic9FXGCgkvecPTBorEUEh6TRR31FWv8
Destination address (m/0/1): YkxHFY58XH4rsf1CFqeU7XK8cqffAypocG

=== Signed transaction ===
Hex: 0100000001abababab...88ac00000000
Hex length: 452 chars (226 bytes)

=== Verifying via API /decode ===
Decoded tx:
  version: 1
  size:    226 bytes
  inputs:  1
    [0] txid=abab...abab vout=0
        scriptSig.asm: 3045022100f5be...[ALL] 0389346e38763622d67754...
  outputs: 2
    [0] value=0.5 sats, addresses=YkxHFY58XH4rsf1CFqeU7XK8cqffAypocG
    [1] value=0.4999774 sats, addresses=YgMic9FXGCgkvecPTBorEUEh6TRR31FWv8

=== Test PASSED: transaction was built and signed successfully ===
```

## Security checklist

- [x] xprv only loaded in `worker-dispenser.php`, never in web process
- [x] Passwords hashed with bcrypt (cost=12)
- [x] Session tokens stored in Redis (or file fallback), 7-day expiry, rotated on login
- [x] Rate limiting on auth endpoints (10/min login, 5/hour register)
- [x] All satoshi math via `bcmath` (no floats)
- [x] SQL via PDO prepared statements only
- [x] Idempotent withdrawal processing (status atomic transitions)
- [x] Sanity check: `POST /broadcast` preceded by `GET /decode` to verify tx
- [x] HD wallet derivation indices tracked per-UTXO (via script lookup)
- [x] Network params passed explicitly to bitcoin-php (no global state leaks)

## Troubleshooting

### "Invalid address" errors from API

If `worker-deposit.php` shows errors like:
```
DepositWatcher swap #1 error: API error on /balance/YZxy...: {"code":-5,"message":"Invalid address"}
```

There are two possible causes:

#### Cause 1: Old swap orders from a different xprv (most common)

If you regenerated your HD wallet keys (e.g. re-ran `bin/generate-wallet.php`), the addresses stored in `swap_orders` and `deposits` tables were created with the OLD xprv. They're no longer valid for your current wallet.

**Fix**: Clear old invalid records:
```bash
# Dry run - see what's invalid:
docker exec -it ys-php php bin/clear-old-orders.php

# Actually delete:
docker exec -it ys-php php bin/clear-old-orders.php --force
```

Or directly via SQL:
```bash
docker exec -it ys-php sqlite3 storage/exchange.sqlite "DELETE FROM swap_orders WHERE status = 'pending';"
```

#### Cause 2: Addresses generated with wrong network params (was a bug, now fixed)

In earlier versions, `HdWallet` called `Bitcoin::setNetwork($network)` in its constructor. This is **global static state** that gets overwritten when both `YentenAdapter` and `SugarchainAdapter` are created in the same process — leading to YTN-adr being generated with Sugarchain params (starting with `S` instead of `Y`).

**Verify** your addresses start with the correct prefix:
- Yenten P2PKH addresses → `Y...`
- Sugarchain P2PKH addresses → `S...`

If they don't, you have the bug. Update to the latest version (already fixed in current code — network is passed explicitly to `getAddress($network)`).

#### Diagnose with debug script

```bash
# Test API connectivity and address validation
docker exec -it ys-php php bin/debug-api.php yenten YYoMoXaUxPGxpbmus23mdGm6guSGVJiocf

# Or with no address (will pick first pending swap from DB)
docker exec -it ys-php php bin/debug-api.php yenten
```

The debug script shows:
1. Configured API URL (with hex dump to detect CRLF)
2. DNS resolution
3. HTTPS connectivity test
4. Raw curl call to `/balance/{addr}`
5. ApiClient wrapper test
6. `/history` endpoint test

### Redis connection refused

If you see `Connection refused [tcp://127.0.0.1:6379]`:
- Make sure Redis is running: `docker compose up -d redis` (or `redis-server` for non-Docker)
- The app has a file-based fallback for cache/sessions, but **rate limiting and atomic operations** require Redis
- Workers will still run, just with reduced concurrency safety

### PHP server won't stay running

If `php -S` exits immediately:
- Check `storage/logs/php-server.log` for errors
- Make sure `vendor/autoload.php` exists (run `composer install`)
- Make sure `.env` exists (run `cp .env.example .env`)
- Make sure `storage/exchange.sqlite` exists (run `php bin/migrate.php`)

### buildAndSignTx fails

Run the unit test:
```bash
php bin/test-build-tx.php yenten
php bin/test-build-tx.php sugarchain
```

Both should print "Test PASSED". If they fail:
- Check that `gmp` extension is loaded: `php -m | grep gmp`
- Check that `bcmath` extension is loaded: `php -m | grep bcmath`
- Check that xprv in `.env` is a valid extended key (starts with `xprv`)


## File structure

```
yenten-sugar-exchange/
├── README.md
├── PLAN.md
├── composer.json
├── composer.lock
├── .env.example
├── .gitignore
├── .dockerignore
├── Dockerfile                  ← PHP 8.2 image with all extensions
├── docker-compose.yml          ← 5 services: php + redis + 3 workers
├── config/
├── public/
│   ├── index.php              ← front controller
│   ├── .htaccess
│   ├── assets/
│   │   ├── css/style.css
│   │   ├── js/{app,i18n,chart,exchange,trade,dashboard}.js
│   │   ├── locales/{en,ru,ja,zh}.json
│   │   └── img/favicon.svg
│   └── views/
│       ├── layout.php
│       ├── home.php
│       ├── exchange.php
│       ├── trade.php
│       ├── login.php
│       ├── register.php
│       └── dashboard.php
├── src/
│   ├── Core/      Config, Database, Redis (with file fallback), Session, Auth, Mailer, Router, Request, Response, View, RateLimit
│   ├── Network/   YentenNetwork, SugarchainNetwork (bitcoin-php Network subclasses)
│   ├── Api/       ApiClient, YentenApi, SugarchainApi
│   ├── Wallet/    HdWallet (with buildAndSignTx), CoinAdapter interface, YentenAdapter, SugarchainAdapter, AdapterRegistry
│   ├── Service/   ExchangeService, TradeService, MatchingEngine, DepositWatcher, WithdrawalDispenser
│   ├── Controller/ AuthController, PageController, ExchangeController, TradeController, WalletController
│   └── Model/     (placeholder for future ORM models)
├── bin/
│   ├── worker-deposit.php      ← long-running deposit watcher
│   ├── worker-dispenser.php    ← long-running payout sender (loads xprv)
│   ├── worker-matcher.php      ← 1-second matching engine
│   ├── cron-aggregate.php      ← 1-minute candlestick aggregation
│   ├── migrate.php             ← run SQL schema
│   ├── generate-wallet.php     ← generate HD master key for a coin
│   ├── test-build-tx.php       ← unit test for buildAndSignTx
│   ├── debug-api.php           ← diagnose API connectivity & address issues
│   └── clear-old-orders.php    ← clean up swap orders with invalid addresses
├── sql/schema.sql              ← SQLite schema
├── cron/
│   ├── cron.sh
│   └── supervisor.conf.example
└── storage/
    ├── logs/
    ├── cache/
    └── exchange.sqlite          ← created on first run
```

## Development notes

### Adding a new coin

1. Create `src/Network/{Coin}Network.php` extending `BitWasp\Bitcoin\Network\Network` with correct prefixes (use `YentenNetwork.php` as a template)
2. Create `src/Api/{Coin}Api.php` similar to `YentenApi.php`
3. Create `src/Wallet/{Coin}Adapter.php` implementing `CoinAdapter`
4. Register in `AdapterRegistry::get()`
5. Add coin to `.env` with `XPUB`/`XPRV`/`HOT_ADDRESS`
6. Update `sql/schema.sql` if needed

### Customizing the exchange rate

The default rate is hardcoded in `.env` as `RATE_YTN_TO_SUGAR=100.0`. For production, replace `ExchangeService::getCurrentRate()` with a real price feed (e.g. weighted average from multiple exchanges) and cache in Redis.

### Theme & language override

User preferences are persisted in `users.lang` and `users.theme`. Guests use cookies (`lang`, `theme`). The frontend reads these via `document.documentElement.dataset`.

### Redis fallback

If Redis is unreachable, the app automatically falls back to a file-based cache in `storage/cache/data/`. This is fine for development but **not for production** — Redis is required for atomic rate limiting and real-time order book updates.

## License

MIT — see source headers.

## Disclaimer

This software handles real cryptocurrency. Always:
- Test with small amounts first
- Verify chainparams against official sources before going live
- Keep xprv in a secure location (HSM/Vault preferred)
- Run workers under isolated users with minimal filesystem permissions
- Maintain cold-storage for the majority of funds (only keep operational float in hot wallet)

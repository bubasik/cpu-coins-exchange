# Yenten ↔ Sugarchain Exchange & Trading Platform — Plan

## 1. Overview

Two-section platform:
- **Instant Exchange** — simple swap form: user enters amount + payout address, system sends funds on confirmation.
- **Trading** — order book with limit & market orders for the YTN/SUGAR pair, candlestick chart, trade history.

Auth: email + password (bcrypt). Themes: light/dark (CSS variables). Languages: EN / RU / JA / ZH.

## 2. Stack

- PHP 8.2 (no framework)
- Composer: `bitwasp/bitcoin`, `predis/predis`, `phpmailer/phpmailer`
- DB: SQLite (single file `storage/exchange.sqlite`)
- Cache / Order book / Sessions: Redis
- Background: long-running PHP workers + cron
- Frontend: Vanilla JS, custom i18n, CSS variables for theming, lightweight chart.js-less candlestick renderer

## 3. Directory layout

```
yenten-sugar-exchange/
├── README.md
├── composer.json
├── .env.example
├── config/         # app, coins, i18n config
├── public/         # web root (front controller + assets + views)
│   ├── index.php
│   ├── .htaccess
│   ├── assets/{css,js,locales,img}
│   └── views/      # PHP-rendered pages
├── src/
│   ├── Core/       # Router, DB, Auth, Config, Mailer, Session, View
│   ├── Network/    # YentenNetwork, SugarchainNetwork (bitcoin-php Network subclasses)
│   ├── Api/        # ApiClient, YentenApi, SugarchainApi
│   ├── Wallet/     # HdWallet, CoinAdapter interface + 2 implementations
│   ├── Service/    # ExchangeService, TradeService, MatchingEngine, DepositWatcher, WithdrawalDispenser
│   ├── Controller/ # Auth, Page, Exchange, Trade, Wallet, Api
│   └── Model/      # User, Order, Trade, Wallet, Deposit, Withdrawal, SwapOrder
├── bin/            # CLI workers
├── sql/schema.sql
├── cron/cron.sh
└── storage/{logs,cache,uploads,exchange.sqlite}
```

## 4. Database schema (SQLite)

- `users(id, email, password_hash, lang, theme, created_at)`
- `user_wallets(user_id, coin, balance, locked)`
- `deposits(id, user_id, coin, address, amount, txid, status, created_at, confirmed_at)`
- `withdrawals(id, user_id, coin, address, amount, fee, txid, status, created_at, sent_at)`
- `swap_orders(id, from_coin, to_coin, from_amount, to_amount, rate, payout_address, refund_address, status, deposit_address, deposit_txid, payout_txid, created_at)`
- `orders(id, user_id, side(buy/sell), type(limit/market), price, amount, filled, status, created_at)`
- `trades(id, maker_order_id, taker_order_id, price, amount, side, created_at)` — fills
- `candles(interval, ts, open, high, low, close, volume)` — aggregated from trades
- `hot_wallets(coin, xpub, last_index)` — HD master public keys
- `withdrawal_keys(id, coin, derivation_path, address, used)` — derived keys for payout txs

## 5. Coin configuration

| Param | Yenten (YTN) | Sugarchain (SUGAR) |
|---|---|---|
| API base | https://api.yentencoin.info | https://api.sugarchain.org |
| P2PKH prefix | 78 (0x4E) | 63 (0x3F) |
| P2SH prefix | 10 (0x0A) | 125 (0x7D) |
| WIF prefix | 123 (0x7B) | 128 (0x80) |
| Bech32 HRP | `ytn` | `sugar` |
| Block spacing | 120s | 5s |
| Deposit confs | 6 | 30 |
| Decimals | 8 | 8 |
| HD path | m/44'/1234'/0'/0/n | m/44/'408'/0'/0/n |

## 6. Flows

### 6.1 Instant Exchange (swap)

1. User fills: from_coin, from_amount, to_coin, payout_address (to_coin).
2. Server creates `swap_orders` row, generates `deposit_address` (HD next index on from_coin).
3. `DepositWatcher` polls `GET /balance/{deposit_address}` every 30s.
4. On balance>0: fetch `/transaction/{txid}`, verify vout has our address.
5. Wait for `deposit_confs` confirmations (compare `/transaction.height` vs `/info.blocks`).
6. Mark deposit confirmed → trigger payout.
7. `WithdrawalDispenser` builds raw tx via `bitcoin-php`, signs locally, calls `POST /broadcast`.
8. Save payout txid, mark swap completed.

### 6.2 Trading (limit/market)

1. Auth required. User must have balance in `user_wallets`.
2. **Limit order**: insert into `orders` (status=open). Lock `amount * price` (buy) or `amount` (sell) in `user_wallets.locked`.
3. `MatchingEngine` worker (every 1s): scan new orders, match against order book.
   - Buy matches Sell where `sell.price <= buy.price`. Fill at maker price.
   - Both orders update `filled`. Trades inserted. Balances moved.
4. **Market order**: matched immediately against best opposite-side prices. No price specified.
5. Chart: `candles` table aggregated every 1 min from `trades`. Frontend polls `/api/chart?interval=1m&limit=200`.
6. Order book snapshot: `/api/orderbook` reads from Redis (mirrored on every match).
7. Trade history: `/api/trades?limit=50` from `trades` table.

### 6.3 Deposit / Withdrawal (Trading section)

Same machinery as swap section:
- Deposit: generate address per user per coin (HD index = user_id * 1000 + n), watcher polls.
- Withdrawal: user submits amount+address → insert `withdrawals` row → dispenser broadcasts.

## 7. Frontend pages

- `/` — Home: market stats, links
- `/exchange` — Instant swap form + order status lookup
- `/trade` — Trading: chart + order book + order form + history (auth required for placing orders)
- `/login`, `/register` — auth pages
- `/dashboard` — balances, deposit addresses, withdrawal form, order history
- `/orders/{id}` — swap order status page

## 8. Theming & i18n

- **Theme**: `data-theme="light|dark"` on `<html>`, all colors via CSS variables.
- **i18n**: `/assets/locales/{en,ru,ja,zh}.json`. JS loads current locale, replaces `data-i18n="key"` elements.
- Language switcher: dropdown with 4 flags. Theme switcher: sun/moon icon.
- Preferences stored in `users.lang`, `users.theme` (logged in) or localStorage (guest).

## 9. Security checklist

- Passwords: bcrypt cost=12
- Sessions: signed JWT cookie (HMAC), 7-day expiry, rotated on login
- CSRF: double-submit cookie on POST forms
- Rate limit: 10 req/min for auth endpoints, 60/min for API
- Private keys (xprv): stored in `.env` (or Vault), only loaded in `bin/worker-*.php` (never in web process)
- Web process can only create withdrawal rows; never signs txs
- All BTC satoshi math via bcmath (avoid float)
- SQL via PDO prepared statements only

## 10. Workers (long-running)

```bash
php bin/worker-deposit.php    # polls deposits every 30s
php bin/worker-dispenser.php  # processes pending withdrawals every 30s
php bin/worker-matcher.php    # matching engine, 1s tick
cron: */5 * * * *  php bin/cron-aggregate.php  # build 1m candles
```

## 11. Deployment

1. `composer install`
2. `cp .env.example .env`, fill in: xprv for YTN & SUGAR, Redis URL, SMTP creds, app secret
3. `php bin/migrate.php` — run `sql/schema.sql`
4. Point web root to `public/`
5. Start workers via supervisor/systemd (see `cron/supervisor.conf.example`)
6. Add cron entries (see `cron/cron.sh`)

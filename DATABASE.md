# Базы данных и хранилища

Полная схема данных. Используются **SQLite** (основное хранилище) + **Redis** (кэш/сессии/order book).

## База данных: SQLite

**Расположение файла**: `storage/exchange.sqlite` (путь настраивается через `DB_PATH` в `.env`)

Создаётся автоматически при первом запуске `php bin/migrate.php`, использует WAL-режим.

### Таблицы

#### `users` — пользователи
| Поле | Тип | Описание |
|---|---|---|
| `id` | INTEGER PK | Автоинкремент |
| `email` | TEXT UNIQUE | Email для логина |
| `password_hash` | TEXT | bcrypt cost=12 |
| `lang` | TEXT | `en`, `ru`, `ja`, `zh` |
| `theme` | TEXT | `dark` или `light` |
| `created_at` | INTEGER | Unix timestamp |

#### `user_wallets` — балансы
| Поле | Тип | Описание |
|---|---|---|
| `user_id` + `coin` | COMPOSITE PK | (user_id, 'YTN'/'SUGAR'/'ADVC') |
| `balance` | INTEGER | Доступный баланс в сатоши |
| `locked` | INTEGER | Зарезервировано под ордера |

#### `deposits` — пополнения
| Поле | Тип | Описание |
|---|---|---|
| `id` | INTEGER PK | |
| `user_id` | INTEGER FK | |
| `coin` | TEXT | YTN/SUGAR/ADVC |
| `address` | TEXT | HD-адрес депозита |
| `amount_sat` | INTEGER | Сумма в сатоши |
| `txid` | TEXT | TxID в блокчейне |
| `height` | INTEGER | Высота блока |
| `confirmations` | INTEGER | Текущие подтверждения |
| `status` | TEXT | `pending` → `confirmed` / `failed` |

#### `withdrawals` — выводы
| Поле | Тип | Описание |
|---|---|---|
| `id` | INTEGER PK | |
| `user_id` | INTEGER FK | |
| `coin` | TEXT | |
| `address` | TEXT | Куда отправить |
| `amount_sat` | INTEGER | Сумма в сатоши |
| `txid` | TEXT | TxID broadcast'нутой tx |
| `fee_rate` | INTEGER | sat/vbyte |
| `status` | TEXT | `pending` → `sending` → `sent` → `completed` / `failed` |

#### `swap_orders` — заявки обмена
| Поле | Тип | Описание |
|---|---|---|
| `id` | INTEGER PK | |
| `ref` | TEXT UNIQUE | Человекочитаемый ID (`SW260628...`) |
| `from_coin` / `to_coin` | TEXT | YTN/SUGAR/ADVC |
| `from_amount_sat` / `to_amount_sat` | INTEGER | Суммы в сатоши |
| `rate` | TEXT | Курс обмена |
| `fee_percent` | REAL | Например 0.5 |
| `payout_address` | TEXT | Куда отправить to_coin |
| `deposit_address` | TEXT | HD-адрес для from_coin |
| `deposit_index` | INTEGER | HD-индекс |
| `deposit_txid` | TEXT | TxID входящего депозита |
| `confirmations` | INTEGER | Текущие подтверждения |
| `payout_txid` | TEXT | TxID выплаты |
| `status` | TEXT | `pending` → `confirmed` → `sending` → `sent` → `completed` |

#### `orders` — ордера биржи
| Поле | Тип | Описание |
|---|---|---|
| `id` | INTEGER PK | |
| `user_id` | INTEGER FK | |
| `pair` | TEXT | `YTN/SUGAR`, `YTN/ADVC`, `SUGAR/ADVC` |
| `side` | TEXT | `buy` / `sell` |
| `type` | TEXT | `limit` / `market` |
| `price_sat` | INTEGER | Цена за 1 base в сатоши quote |
| `amount_sat` | INTEGER | Объём в сатоши |
| `filled_sat` | INTEGER | Сколько исполнено |
| `status` | TEXT | `open` → `matching` → `partial` → `filled` / `cancelled` |

#### `trades` — исполненные сделки
| Поле | Тип | Описание |
|---|---|---|
| `id` | INTEGER PK | |
| `maker_order_id` | INTEGER FK | Мейкер |
| `taker_order_id` | INTEGER FK | Тейкер |
| `price_sat` | INTEGER | Цена сделки |
| `amount_sat` | INTEGER | Объём |
| `side` | TEXT | `buy` / `sell` |

#### `candles` — свечи графика
| Поле | Тип | Описание |
|---|---|---|
| `interval` + `ts` | COMPOSITE PK | `('1m', unix_timestamp_minute)` |
| `open` / `high` / `low` / `close` | INTEGER | OHLC в сатоши |
| `volume` | INTEGER | Объём |

#### `hot_wallets` — состояние HD-кошельков
| Поле | Тип | Описание |
|---|---|---|
| `coin` | TEXT PK | YTN/SUGAR/ADVC |
| `last_index` | INTEGER | Последний HD-индекс |

#### `audit_log` — журнал аудита
| Поле | Тип | Описание |
|---|---|---|
| `id` | INTEGER PK | |
| `user_id` | INTEGER | |
| `action` | TEXT | `login`, `withdraw`, `swap.create` |
| `details` | TEXT | JSON |
| `ip` | TEXT | IP пользователя |

## Redis

| Ключ | TTL | Что хранит |
|---|---|---|
| `sess:{token}` | 7 дней | Сессия пользователя |
| `rate:{action}:{ip}:{window}` | 60 сек | Rate limit счётчик |
| `book:bids:{pair}` | ∞ | Sorted set bid-ордеров |
| `book:asks:{pair}` | ∞ | Sorted set ask-ордеров |
| `book:orders:{pair}` | ∞ | Hash: order_id → remaining |
| `network_stats:all` | 60 сек | Кэш /info для всех монет |
| `network_stats:fee:{coin}` | 30 сек | Кэш /fee |
| `rate:{from}:{to}` | 300 сек | Курс обмена |
| `hd:index:{coin}` | ∞ | HD-индекс счетчик |

## Файловое хранилище

```
storage/
├── exchange.sqlite          ← SQLite БД
├── logs/                    ← Логи PHP и воркеров
├── cache/data/              ← File-based Redis fallback
└── backups/                 ← SQLite бэкапы (cron)
```

## Что хранить вне БД

| Что | Где | Почему |
|---|---|---|
| **xprv** | `.env` или HSM/Vault | Контроль над всеми средствами |
| **SMTP пароль** | `.env` | Доступ к почте |
| **APP_SECRET** | `.env` | HMAC-подпись сессий |

Web-процесс **никогда** не загружает `xprv` — только `worker-dispenser.php`.

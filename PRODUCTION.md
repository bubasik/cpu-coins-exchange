# Production Deployment Guide

Это руководство описывает рекомендуемый способ запуска биржи в production — **bare metal** (PHP-FPM + Nginx + Redis + Supervisor) вместо Docker.

## Почему bare metal, а не Docker?

Для биржи (где есть `xprv` — мастер-приватные ключи от всех средств) **безопасность критичнее**, чем удобство изоляции.

| Критерий | Docker (как сейчас) | Bare Metal (рекомендуется) |
|---|---|---|
| **Безопасность xprv** | ❌ Все контейнеры под root, web-процесс может прочитать `.env` через volume mount | ✅ Web (www-data) физически не может прочитать `.env` — он принадлежит пользователю `dispenser` с правами 600 |
| **Производительность** | `php -S` (однопоточный, медленный) | PHP-FPM с opcache (в 2-3 раза быстрее, многопоточный) |
| **Статика (CSS/JS)** | Через PHP (медленно) | Nginx отдаёт напрямую (в 10x быстрее) |
| **SQLite** | В контейнере, риск потери при пересборке | На диске хоста, WAL-режим, надёжно |
| **Отладка** | `docker exec`, сложно | `tail -f`, `strace`, `htop` напрямую |
| **Изоляция** | ✅ Полная | ⚠ Частичная (но достаточная через пользователей) |

**Вывод:** Docker хорош для разработки. Для production-биржи — bare metal безопаснее и быстрее.

---

## Архитектура production

```
                    Internet
                       │
                       ▼
              ┌─────────────────┐
              │   Nginx (443)   │  ← HTTPS, Let's Encrypt
              │   static files  │  ← CSS/JS/images напрямую
              └────────┬────────┘
                       │ fastcgi
                       ▼
              ┌─────────────────┐
              │  PHP-FPM 8.2    │  ← web process
              │  (user: www-data)│  ← НЕ имеет доступа к .env
              └────────┬────────┘
                       │
              ┌────────┴────────┐
              │                 │
       ┌──────▼──────┐  ┌──────▼──────┐
       │  SQLite     │  │   Redis     │
       │  (WAL mode) │  │  (sessions, │
       │             │  │   orderbook)│
       └─────────────┘  └─────────────┘

       ┌─────────────────────────────┐
       │  Supervisor (3 workers):    │  ← runs as user 'dispenser'
       │  - worker-deposit.php       │     (имеет доступ к .env и xprv)
       │  - worker-dispenser.php     │     (НЕ имеет web-доступа)
       │  - worker-matcher.php       │
       └─────────────────────────────┘
```

**Ключевое отличие от Docker:** web-процесс (PHP-FPM) работает под пользователем `www-data` и **физически не может** прочитать `.env` (там xprv). Только воркер `worker-dispenser` (под пользователем `dispenser`) имеет к нему доступ.

---

## Быстрая установка (1 команда)

В репозитории есть скрипт `prod-install.sh`, который делает всё автоматически.

### Шаг 1: Подготовь VPS

- **OS:** Debian 12 или Ubuntu 22.04+
- **RAM:** минимум 1GB (рекомендуется 2GB)
- **Disk:** 10GB
- **Доступ:** root (sudo)
- **Домен:** направлен на IP сервера (A-запись в DNS)

### Шаг 2: Скачай и запусти

```bash
# Клонируй репозиторий
git clone https://github.com/bubasik/cpu-coins-exchange.git
cd cpu-coins-exchange

# Отредактируй домен в начале скрипта
nano prod-install.sh
# Замени: DOMAIN="exchange.your-domain.tld" на свой домен

# Запусти установку
sudo bash prod-install.sh
```

Скрипт за ~3 минуты:
1. Установит PHP 8.2-FPM, Nginx, Redis, Supervisor, Certbot
2. Создаст пользователя `dispenser` (для воркеров)
3. Развернёт приложение в `/opt/yenten-sugar`
4. Настроит Nginx с security headers
5. Запустит 3 воркера через Supervisor
6. Настроит cron для агрегации свечей

### Шаг 3: Настрой .env

```bash
nano /opt/yenten-sugar/.env
```

Заполни:
```bash
APP_SECRET=<64 случайных hex символов>  # openssl rand -hex 32
YTN_XPRV=xprv...     # от php bin/generate-wallet.php yenten
YTN_XPUB=xpub...
YTN_HOT_ADDRESS=Y...
SUGAR_XPRV=xprv...
SUGAR_XPUB=xpub...
SUGAR_HOT_ADDRESS=S...
ADVC_XPRV=xprv...
ADVC_XPUB=xpub...
ADVC_HOT_ADDRESS=A...
REDIS_HOST=127.0.0.1
MAIL_HOST=smtp.your-provider.com
MAIL_USER=noreply@your-domain.tld
MAIL_PASSWORD=...
```

Защити `.env`:
```bash
chown dispenser:dispenser /opt/yenten-sugar/.env
chmod 600 /opt/yenten-sugar/.env
```

### Шаг 4: Сгенерируй HD-ключи (если ещё не сделал)

```bash
sudo -u dispenser php /opt/yenten-sugar/bin/generate-wallet.php yenten
sudo -u dispenser php /opt/yenten-sugar/bin/generate-wallet.php sugarchain
sudo -u dispenser php /opt/yenten-sugar/bin/generate-wallet.php adventurecoin
```

Скопируй вывод в `.env`, затем:
```bash
chown dispenser:dispenser /opt/yenten-sugar/.env
chmod 600 /opt/yenten-sugar/.env
supervisorctl restart ys-deposit ys-dispenser
```

### Шаг 5: Настрой HTTPS

```bash
certbot --nginx -d exchange.your-domain.tld
```

Certbot автоматически:
- Получит сертификат Let's Encrypt
- Настроит Nginx для HTTPS
- Включит редирект HTTP → HTTPS
- Добавит autorenewal через cron

### Шаг 6: Настрой firewall

```bash
ufw allow 22/tcp   # SSH
ufw allow 80/tcp   # HTTP (для редиректа и Let's Encrypt)
ufw allow 443/tcp  # HTTPS
ufw enable
```

---

## Ручная установка (пошагово)

Если не хочешь использовать скрипт, вот подробные шаги.

### 1. Установка пакетов

```bash
sudo apt update
sudo apt install -y nginx redis-server supervisor sqlite3 git unzip \
    certbot python3-certbot-nginx

# PHP 8.2 (на Ubuntu 22.04 нужен PPA)
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install -y php8.2-fpm php8.2-cli php8.2-sqlite3 php8.2-mbstring \
    php8.2-curl php8.2-bcmath php8.2-gmp php8.2-xml php8.2-zip \
    php8.2-redis php8.2-opcache
```

### 2. Создание пользователя dispenser

```bash
sudo useradd -r -s /bin/bash -d /home/dispenser -m dispenser
```

Этот пользователь будет запускать воркеры и иметь доступ к `.env` (xprv).

### 3. Деплой приложения

```bash
sudo mkdir -p /opt/yenten-sugar
sudo git clone https://github.com/bubasik/cpu-coins-exchange.git /opt/yenten-sugar
cd /opt/yenten-sugar

# Установка Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Установка зависимостей
sudo composer install --no-interaction --no-dev --optimize-autoloader

# Создание каталогов
sudo mkdir -p storage/logs storage/cache storage/uploads storage/backups
sudo chown -R www-data:www-data /opt/yenten-sugar/storage
sudo chmod -R 775 /opt/yenten-sugar/storage

# Миграция БД
sudo -u www-data php bin/migrate.php
```

### 4. Настройка .env

```bash
sudo cp .env.example .env
sudo nano .env  # заполни ключами
sudo chown dispenser:dispenser .env
sudo chmod 600 .env
```

### 5. Настройка PHP-FPM

```bash
sudo nano /etc/php/8.2/fpm/conf.d/99-yenten-sugar.ini
```

Вставь:
```ini
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 4000
opcache.revalidate_freq = 60
opcache.validate_timestamps = 0
expose_php = Off
display_errors = Off
log_errors = On
error_log = /var/log/yenten-sugar/php-errors.log
memory_limit = 256M
max_execution_time = 30
```

```bash
sudo mkdir -p /var/log/yenten-sugar
sudo chown www-data:www-data /var/log/yenten-sugar
sudo systemctl restart php8.2-fpm
```

### 6. Настройка Nginx

```bash
sudo nano /etc/nginx/sites-available/yenten-sugar
```

Вставь (замени `exchange.your-domain.tld`):
```nginx
server {
    listen 80;
    server_name exchange.your-domain.tld;
    root /opt/yenten-sugar/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    # Запрет доступа к sensitive файлам
    location ~ /\.(?!well-known).* { deny all; }
    location ~ /(composer\.|\.env|\.git) { deny all; }
    location ~ ^/(src|bin|sql|cron|config|storage/logs|storage/cache|storage/backups)/ { deny all; }

    # Статика напрямую
    location /assets/ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        try_files $uri =404;
    }
}
```

```bash
sudo ln -sf /etc/nginx/sites-available/yenten-sugar /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl restart nginx
```

### 7. Настройка Supervisor (воркеры)

```bash
sudo nano /etc/supervisor/conf.d/yenten-sugar.conf
```

Вставь:
```ini
[program:ys-deposit]
command=php /opt/yenten-sugar/bin/worker-deposit.php
directory=/opt/yenten-sugar
user=dispenser
autostart=true
autorestart=true
stderr_logfile=/var/log/yenten-sugar/worker-deposit.err.log
stdout_logfile=/var/log/yenten-sugar/worker-deposit.out.log

[program:ys-dispenser]
command=php /opt/yenten-sugar/bin/worker-dispenser.php
directory=/opt/yenten-sugar
user=dispenser
autostart=true
autorestart=true
stderr_logfile=/var/log/yenten-sugar/worker-dispenser.err.log
stdout_logfile=/var/log/yenten-sugar/worker-dispenser.out.log

[program:ys-matcher]
command=php /opt/yenten-sugar/bin/worker-matcher.php
directory=/opt/yenten-sugar
user=dispenser
autostart=true
autorestart=true
stderr_logfile=/var/log/yenten-sugar/worker-matcher.err.log
stdout_logfile=/var/log/yenten-sugar/worker-matcher.out.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start ys-deposit ys-dispenser ys-matcher
```

### 8. Настройка cron

```bash
sudo crontab -u dispenser -e
```

Добавь:
```cron
* * * * * php /opt/yenten-sugar/bin/cron-aggregate.php >> /var/log/yenten-sugar/cron.log 2>&1
```

### 9. HTTPS (Let's Encrypt)

```bash
sudo certbot --nginx -d exchange.your-domain.tld
```

---

## Управление в production

### Статус воркеров
```bash
sudo supervisorctl status
# ys-deposit    RUNNING   pid 12345
# ys-dispenser  RUNNING   pid 12346
# ys-matcher    RUNNING   pid 12347
```

### Перезапуск воркеров
```bash
sudo supervisorctl restart ys-deposit ys-dispenser ys-matcher
```

### Логи
```bash
# Воркеры
sudo tail -f /var/log/yenten-sugar/worker-deposit.out.log
sudo tail -f /var/log/yenten-sugar/worker-dispenser.out.log
sudo tail -f /var/log/yenten-sugar/worker-matcher.out.log

# PHP errors
sudo tail -f /var/log/yenten-sugar/php-errors.log

# Nginx
sudo tail -f /var/log/nginx/access.log
sudo tail -f /var/log/nginx/error.log

# Cron
sudo tail -f /var/log/yenten-sugar/cron.log
```

### Обновление кода
```bash
cd /opt/yenten-sugar
sudo -u www-data git pull origin main
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo supervisorctl restart ys-deposit ys-dispenser ys-matcher
```

### Резервное копирование
```bash
# SQLite (можно на лету)
sudo sqlite3 /opt/yenten-sugar/storage/exchange.sqlite ".backup /opt/yenten-sugar/storage/backups/exchange-$(date +%Y%m%d-%H%M).sqlite"

# Redis
sudo redis-cli BGSAVE
sudo cp /var/lib/redis/dump.rdb /opt/yenten-sugar/storage/backups/redis-$(date +%Y%m%d-%H%M).rdb

# .env (содержит xprv — критично!)
sudo cp /opt/yenten-sugar/.env /opt/yenten-sugar/storage/backups/env-$(date +%Y%m%d).bak
sudo gpg -c /opt/yenten-sugar/storage/backups/env-*.bak
```

Добавь в cron (на сервере):
```cron
0 */6 * * * sqlite3 /opt/yenten-sugar/storage/exchange.sqlite ".backup /opt/yenten-sugar/storage/backups/exchange-$(date +\%Y\%m\%d-\%H\%M).sqlite"
```

---

## Безопасность

### Разделение привилегий (ключевое отличие от Docker)

```
┌─────────────────────────────────────────────────────┐
│  Nginx (порт 443)                                   │
│  ↓                                                  │
│  PHP-FPM (user: www-data)                           │
│  - НЕ может читать .env (принадлежит dispenser)     │
│  - НЕ может читать xprv                             │
│  - Может: рендерить страницы, API, БД, Redis        │
└─────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────┐
│  Supervisor → worker-dispenser (user: dispenser)    │
│  - МОЖЕТ читать .env (владелец, mode 600)           │
│  - МОЖЕТ загружать xprv                             │
│  - Строит и подписывает payout-транзакции           │
│  - НЕ имеет web-доступа (нет listen на портах)      │
└─────────────────────────────────────────────────────┘
```

Даже если хакер получит RCE (Remote Code Execution) через web (PHP-FPM), он **не сможет** прочитать `xprv` — файл принадлежит другому пользователю с правами 600.

### Чек-лист безопасности

- [ ] `.env` принадлежит `dispenser:dispenser`, права `600`
- [ ] `storage/` принадлежит `www-data`, права `775`
- [ ] Nginx блокирует доступ к `.env`, `src/`, `bin/`, `sql/`, `storage/logs/`
- [ ] HTTPS включён (Let's Encrypt)
- [ ] Firewall (ufw) пропускает только 22, 80, 443
- [ ] SSH-логин по ключу, не по паролю
- [ ] Fail2ban для защиты от brute-force SSH
- [ ] Автоматические бэкапы SQLite + .env (раз в 6 часов)
- [ ] `xprv` хранится только в `.env`, никогда в БД или git

### Дополнительная защита SSH

```bash
# Запретить root-логин по паролю
sudo nano /etc/ssh/sshd_config
# PermitRootLogin no
# PasswordAuthentication no  (если настроил SSH-ключи)

sudo systemctl restart sshd

# Установить fail2ban
sudo apt install fail2ban
sudo systemctl enable fail2ban
```

---

## Сравнение с Docker

Если хочешь оставить Docker, вот что нужно изменить для production:

```yaml
# docker-compose.prod.yml
services:
  php:
    image: php:8.2-fpm  # ← FPM вместо CLI
    volumes:
      - ./:/app
      - ./php-fpm.conf:/usr/local/etc/php-fpm.d/www.conf
    user: "1000:1000"  # ← не root
    restart: always

  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./:/app
      - ./nginx.conf:/etc/nginx/conf.d/default.conf
      - /etc/letsencrypt:/etc/letsencrypt:ro
    restart: always
```

Но это **не решает** проблему доступа к `.env` — все контейнеры могут его прочитать через volume mount. Для настоящей изоляции нужен bare metal.

---

## Мониторинг

### Простая проверка здоровья (health check)

```bash
# Проверка API
curl -s https://exchange.your-domain.tld/api/status | python3 -m json.tool

# Проверка воркеров
sudo supervisorctl status | grep -v RUNNING && echo "ALERT: Some workers are down!"

# Проверка места на диске
df -h / | tail -1 | awk '{if ($5+0 > 80) print "ALERT: Disk usage " $5}'
```

Добавь в cron:
```cron
*/5 * * * * /opt/yenten-sugar/bin/health-check.sh >> /var/log/yenten-sugar/health.log 2>&1
```

### Мониторинг баланса hot wallets

```bash
# Проверь баланс hot wallets через API
curl -s https://api.yentencoin.info/balance/<YTN_HOT_ADDRESS>
curl -s https://api.sugarchain.org/balance/<SUGAR_HOT_ADDRESS>
curl -s https://api2.adventurecoin.quest/balance/<ADVC_HOT_ADDRESS>
```

Если баланс < 1 монеты — нужно пополнить hot wallet (иначе выплаты не будут проходить).

---

## Устранение неисправностей

### Воркер не запускается
```bash
sudo supervisorctl status ys-deposit
sudo tail -50 /var/log/yenten-sugar/worker-deposit.err.log
```

### PHP-FPM не отвечает
```bash
sudo systemctl status php8.2-fpm
sudo tail -20 /var/log/php8.2-fpm.log
```

### Nginx отдаёт 502
```bash
sudo tail -20 /var/log/nginx/error.log
# Проверь что PHP-FPM запущен
sudo systemctl restart php8.2-fpm
```

### SQLite заблокирована
```bash
sudo sqlite3 /opt/yenten-sugar/storage/exchange.sqlite "PRAGMA journal_mode;"
# Должно быть: wal
sudo chown www-data:www-data /opt/yenten-sugar/storage/exchange.sqlite*
```

### Redis недоступен
```bash
sudo systemctl status redis-server
redis-cli ping
# Должно: PONG
```

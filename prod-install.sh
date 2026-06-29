#!/bin/bash
# ============================================================
# Production deployment script for Yenten-Sugar Exchange
# Target: Debian/Ubuntu VPS with PHP-FPM + Nginx + Redis + Supervisor
# ============================================================
#
# Run as root:
#   sudo bash prod-install.sh
#
# This script:
#   1. Installs PHP 8.2, Nginx, Redis, Supervisor, Certbot
#   2. Creates isolated users (www-data for web, dispenser for workers)
#   3. Deploys the app to /opt/yenten-sugar
#   4. Configures Nginx with HTTPS
#   5. Sets up Supervisor for 3 background workers
#   6. Sets up cron for candle aggregation
#
# After installation:
#   - Web: https://your-domain.tld
#   - PHP-FPM: /run/php/php8.2-fpm.sock
#   - Workers: supervisorctl status
#   - Logs: /var/log/yenten-sugar/
# ============================================================

set -e

# === Configuration (EDIT THESE) ===
DOMAIN="exchange.your-domain.tld"
APP_DIR="/opt/yenten-sugar"
APP_USER="www-data"
WORKER_USER="dispenser"
PHP_VERSION="8.2"

echo "=== Production Installation ==="
echo "Domain: $DOMAIN"
echo "App dir: $APP_DIR"
echo ""

# === 1. Install system packages ===
echo "[1/7] Installing system packages..."
apt-get update -qq
apt-get install -y -qq \
    software-properties-common \
    nginx \
    redis-server \
    supervisor \
    sqlite3 \
    certbot python3-certbot-nginx \
    git \
    unzip \
    > /dev/null

# Add PHP 8.2 repository (Debian 12 has 8.2, Ubuntu 22.04 needs Sury PPA)
if ! apt-cache show php8.2-fpm > /dev/null 2>&1; then
    echo "  Adding PHP PPA..."
    add-apt-repository -y ppa:ondrej/php > /dev/null 2>&1 || true
    apt-get update -qq
fi

apt-get install -y -qq \
    php${PHP_VERSION}-fpm \
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-sqlite3 \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-gmp \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-zip \
    php${PHP_VERSION}-redis \
    php${PHP_VERSION}-opcache \
    > /dev/null

echo "  ✓ PHP ${PHP_VERSION} + extensions installed"

# === 2. Create isolated worker user ===
echo "[2/7] Creating worker user '$WORKER_USER'..."
if ! id "$WORKER_USER" &>/dev/null; then
    useradd -r -s /bin/bash -d /home/$WORKER_USER -m $WORKER_USER
    echo "  ✓ User $WORKER_USER created (for background workers, has xprv access)"
else
    echo "  ✓ User $WORKER_USER already exists"
fi

# === 3. Deploy app ===
echo "[3/7] Deploying app to $APP_DIR..."
mkdir -p $APP_DIR

if [ -d "$APP_DIR/.git" ]; then
    echo "  Updating existing repo..."
    cd $APP_DIR
    git pull origin main
else
    echo "  Cloning from GitHub..."
    git clone https://github.com/bubasik/cpu-coins-exchange.git $APP_DIR
    cd $APP_DIR
fi

# Install Composer
if [ ! -f /usr/local/bin/composer ]; then
    echo "  Installing Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer > /dev/null
fi

echo "  Installing PHP dependencies..."
composer install --no-interaction --no-dev --optimize-autoloader 2>&1 | tail -3

# Create storage directories
mkdir -p storage/logs storage/cache storage/uploads storage/backups
chown -R $APP_USER:$APP_USER $APP_DIR/storage
chmod -R 775 $APP_DIR/storage

# Restrict access: only $WORKER_USER can read .env (has xprv)
if [ -f $APP_DIR/.env ]; then
    chown $WORKER_USER:$WORKER_USER $APP_DIR/.env
    chmod 600 $APP_DIR/.env
    echo "  ✓ .env secured (owned by $WORKER_USER, mode 600)"
else
    echo "  ⚠ .env not found! Copy from .env.example and fill in keys:"
    echo "    cp $APP_DIR/.env.example $APP_DIR/.env"
    echo "    nano $APP_DIR/.env"
    echo "    chown $WORKER_USER:$WORKER_USER $APP_DIR/.env"
    echo "    chmod 600 $APP_DIR/.env"
fi

# Run migrations
echo "  Running DB migrations..."
su -s /bin/bash $APP_USER -c "php $APP_DIR/bin/migrate.php"

echo "  ✓ App deployed"

# === 4. Configure PHP-FPM ===
echo "[4/7] Configuring PHP-FPM..."
PHP_FPM_POOL="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"

# Ensure PHP-FPM runs as www-data
sed -i 's/^user = .*/user = www-data/' $PHP_FPM_POOL
sed -i 's/^group = .*/group = www-data/' $PHP_FPM_POOL

# Optimize for production
cat > /etc/php/${PHP_VERSION}/fpm/conf.d/99-yenten-sugar.ini <<EOF
; Production optimizations
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 4000
opcache.revalidate_freq = 60
opcache.validate_timestamps = 0
opcache.save_comments = 1

; Security
expose_php = Off
display_errors = Off
log_errors = On
error_log = /var/log/yenten-sugar/php-errors.log

; Performance
memory_limit = 256M
max_execution_time = 30
max_input_vars = 1000
EOF

mkdir -p /var/log/yenten-sugar
chown $APP_USER:$APP_USER /var/log/yenten-sugar

systemctl restart php${PHP_VERSION}-fpm
echo "  ✓ PHP-FPM configured and restarted"

# === 5. Configure Nginx ===
echo "[5/7] Configuring Nginx..."
cat > /etc/nginx/sites-available/yenten-sugar <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name DOMAIN_PLACEHOLDER;
    root /opt/yenten-sugar/public;
    index index.php;

    # Redirect HTTP to HTTPS (uncomment after certbot)
    # return 301 https://$host$request_uri;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Deny access to sensitive files
    location ~ /\.(?!well-known).* {
        deny all;
        access_log off;
        log_not_found off;
    }
    location ~ /(composer\.(json|lock)|\.env|\.gitignore|README\.md|ADDING-COINS\.md|DATABASE\.md|PLAN\.md|Dockerfile|docker-compose\.yml) {
        deny all;
    }
    location ~ ^/(src|bin|sql|cron|config|storage/logs|storage/cache|storage/backups)/ {
        deny all;
    }

    # Static files (CSS, JS, images) — serve directly, no PHP
    location /assets/ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # Main application
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTPS $https_if_not_empty;

        # Security
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        try_files $uri =404;

        # Performance
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_read_timeout 30;
    }
}
EOF

sed -i "s|DOMAIN_PLACEHOLDER|$DOMAIN|g" /etc/nginx/sites-available/yenten-sugar
ln -sf /etc/nginx/sites-available/yenten-sugar /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

nginx -t
systemctl restart nginx
echo "  ✓ Nginx configured for $DOMAIN"

# === 6. Configure Supervisor for workers ===
echo "[6/7] Configuring Supervisor..."
cat > /etc/supervisor/conf.d/yenten-sugar.conf <<EOF
; Background workers — run as user 'dispenser' (has xprv access)
; Web process (PHP-FPM) runs as 'www-data' and CANNOT read .env

[program:ys-deposit]
command=php $APP_DIR/bin/worker-deposit.php
directory=$APP_DIR
user=$WORKER_USER
numprocs=1
autostart=true
autorestart=true
startretries=3
stderr_logfile=/var/log/yenten-sugar/worker-deposit.err.log
stdout_logfile=/var/log/yenten-sugar/worker-deposit.out.log
environment=HOME="/home/$WORKER_USER"

[program:ys-dispenser]
command=php $APP_DIR/bin/worker-dispenser.php
directory=$APP_DIR
user=$WORKER_USER
numprocs=1
autostart=true
autorestart=true
startretries=3
stderr_logfile=/var/log/yenten-sugar/worker-dispenser.err.log
stdout_logfile=/var/log/yenten-sugar/worker-dispenser.out.log
environment=HOME="/home/$WORKER_USER"

[program:ys-matcher]
command=php $APP_DIR/bin/worker-matcher.php
directory=$APP_DIR
user=$WORKER_USER
numprocs=1
autostart=true
autorestart=true
startretries=3
stderr_logfile=/var/log/yenten-sugar/worker-matcher.err.log
stdout_logfile=/var/log/yenten-sugar/worker-matcher.out.log
environment=HOME="/home/$WORKER_USER"
EOF

supervisorctl reread
supervisorctl update
supervisorctl start ys-deposit ys-dispenser ys-matcher 2>/dev/null || true
echo "  ✓ Supervisor configured (3 workers running as '$WORKER_USER')"

# === 7. Setup cron ===
echo "[7/7] Setting up cron..."
CRON_LINE="* * * * * $WORKER_USER php $APP_DIR/bin/cron-aggregate.php >> /var/log/yenten-sugar/cron.log 2>&1"
( crontab -l -u $WORKER_USER 2>/dev/null | grep -v "cron-aggregate" ; echo "$CRON_LINE" ) | crontab -u $WORKER_USER -
echo "  ✓ Cron configured (candle aggregation every minute)"

# === Summary ===
echo ""
echo "=== Installation Complete ==="
echo ""
echo "Web:           http://$DOMAIN"
echo "App dir:       $APP_DIR"
echo "PHP-FPM:       /run/php/php${PHP_VERSION}-fpm.sock"
echo "Workers:       supervisorctl status"
echo "Logs:          /var/log/yenten-sugar/"
echo "DB:            $APP_DIR/storage/exchange.sqlite"
echo ""
echo "=== Next Steps ==="
echo ""
echo "1. Configure .env (if not done):"
echo "   nano $APP_DIR/.env"
echo "   chown $WORKER_USER:$WORKER_USER $APP_DIR/.env"
echo "   chmod 600 $APP_DIR/.env"
echo "   supervisorctl restart ys-deposit ys-dispenser"
echo ""
echo "2. Generate HD wallet keys:"
echo "   sudo -u $WORKER_USER php $APP_DIR/bin/generate-wallet.php yenten"
echo "   sudo -u $WORKER_USER php $APP_DIR/bin/generate-wallet.php sugarchain"
echo "   sudo -u $WORKER_USER php $APP_DIR/bin/generate-wallet.php adventurecoin"
echo ""
echo "3. Setup HTTPS (Let's Encrypt):"
echo "   certbot --nginx -d $DOMAIN"
echo ""
echo "4. Setup firewall:"
echo "   ufw allow 22/tcp"
echo "   ufw allow 80/tcp"
echo "   ufw allow 443/tcp"
echo "   ufw enable"
echo ""
echo "5. Verify workers are running:"
echo "   supervisorctl status"
echo ""
echo "=== Security Notes ==="
echo "- .env (xprv) is owned by '$WORKER_USER', mode 600"
echo "- Web process (www-data) CANNOT read .env"
echo "- Only worker-dispenser (runs as '$WORKER_USER') loads xprv"
echo "- Nginx denies access to .env, src/, bin/, sql/, storage/logs/"
echo ""

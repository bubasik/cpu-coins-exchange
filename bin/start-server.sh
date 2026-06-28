#!/bin/bash
# Persistent launcher for the Yenten-Sugar Exchange PHP backend.
# Restarts PHP server if it dies. Logs to storage/logs/php-server.log.

export PATH="$HOME/.local/bin:$PATH"
cd "$(dirname "$0")/.."

# Ensure DB exists
if [ ! -f storage/exchange.sqlite ]; then
    php bin/migrate.php
fi

mkdir -p storage/logs storage/cache

# Start PHP server (with auto-restart on exit)
while true; do
    echo "[$(date -Iseconds)] Starting PHP server on :8080..."
    php -S 0.0.0.0:8080 -t public/ 2>&1 | tee -a storage/logs/php-server.log
    echo "[$(date -Iseconds)] PHP server exited with code $?, restarting in 2s..."
    sleep 2
done

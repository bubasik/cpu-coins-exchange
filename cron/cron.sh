#!/bin/bash
# Cron entries for Yenten-Sugar Exchange
# Add to crontab of the project user:
#   crontab -e
#   */1 * * * * /path/to/yenten-sugar-exchange/cron/cron.sh

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_BIN=$(which php)

# Aggregate 1m candles every minute
* * * * * cd $PROJECT_DIR && $PHP_BIN bin/cron-aggregate.php >> storage/logs/cron.log 2>&1

# Backup database every 6 hours
0 */6 * * * cd $PROJECT_DIR && cp storage/exchange.sqlite storage/backups/exchange-$(date +\%Y\%m\%d-\%H\%M).sqlite

# Clean old logs every day at 4am
0 4 * * * find $PROJECT_DIR/storage/logs -type f -mtime +30 -delete

#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Run database migrations (apply sql/schema.sql).
 * Usage: php bin/migrate.php
 */

use App\Core\Config;
use App\Core\Database;

require __DIR__ . '/../vendor/autoload.php';
Config::load();

echo "Running migrations...\n";
Database::migrate();
echo "Done.\n";

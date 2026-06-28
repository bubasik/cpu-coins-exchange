# Dockerfile for Yenten-Sugar Exchange PHP backend
#
# PHP 8.2 with all required extensions PRE-INSTALLED in the image.
# This avoids the need to run `docker-php-ext-install` at every container start.
#
# `composer install` is NOT run at build time, because docker-compose mounts
# the project directory at /app (shadowing any /app/vendor built into the image).
# Instead, it runs at container startup via the `command:` in docker-compose.yml.
#
# Usage (with docker-compose):
#   docker compose up -d
#   # First start: composer install runs automatically (~30s), then PHP server starts
#
# Usage (standalone, no compose):
#   docker build -t yenten-sugar-exchange .
#   docker run --rm -p 8080:8080 -v $(pwd):/app yenten-sugar-exchange \
#     sh -c "composer install --no-interaction --optimize-autoloader && php bin/migrate.php && php -S 0.0.0.0:8080 -t public/"

FROM php:8.2-cli

# Install system libraries needed by PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libsqlite3-dev \
    libzip-dev \
    libgmp-dev \
    libssl-dev \
    unzip \
    git \
    sqlite3 \
    && rm -rf /var/lib/apt/lists/*

# Pre-install all PHP extensions the project needs (so they're in the image, not built at runtime)
RUN docker-php-ext-install -j$(nproc) \
    pdo_sqlite \
    pdo_mysql \
    bcmath \
    gmp \
    zip \
    opcache \
    sockets

# Install Redis extension (native phpredis, used alongside predis/predis)
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer globally
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Working directory
WORKDIR /app

# Default command — overridden by docker-compose.yml for actual services
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public/"]

# syntax=docker/dockerfile:1
#
# One Dockerfile, two destinations (R0.6):
#   target: dev   -> used by docker-compose.yml for local development
#   (last stage)  -> prod, built by Railway
#
# Stage order matters: `prod` is LAST so Railway builds it by default.

# =============================================================== base
# PHP runtime + the extensions this app needs, shared by every stage.
FROM php:8.4-fpm-bookworm AS base

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        libsqlite3-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        pdo_sqlite \
        bcmath \
        intl \
        zip \
        opcache \
        pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-quiz.ini

WORKDIR /var/www/html

# ========================================================== toolchain
# base + Node. Exists because Wayfinder's Vite plugin calls
# `php artisan wayfinder:generate`, so asset builds need both runtimes
# in one image (R0.2).
FROM base AS toolchain

ARG NODE_MAJOR=24
RUN curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# ================================================================ dev
# Runs php-fpm. Source arrives via bind mount, so nothing is COPYed in.
# Runs as root on purpose: this targets Docker Desktop on Windows, where
# UID remapping buys nothing and only causes bind-mount permission pain.
FROM toolchain AS dev

COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 9000 5173

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm", "-F"]

# ============================================================== build
# Installs dependencies and compiles assets for production.
FROM toolchain AS build

# PHP deps first, so a source-only change does not re-resolve composer.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# Then JS deps.
COPY package.json package-lock.json ./
RUN npm ci

COPY . .

# `npm run build` triggers wayfinder:generate, which boots the framework —
# hence a throwaway .env with a throwaway key. Real config comes from
# Railway's environment at runtime; nothing here is cached into the image.
#
# ORDER MATTERS. `composer install` above ran with --no-autoloader, so
# vendor/autoload.php does not exist yet and ANY artisan call before
# dump-autoload dies with "Failed opening required vendor/autoload.php".
# dump-autoload must therefore come first, and it needs the .env in place
# because its post-autoload-dump hook runs `artisan package:discover`.
RUN cp .env.example .env \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan key:generate --no-interaction \
    && npm run build \
    && rm -rf node_modules .env

# =============================================================== prod
# FrankenPHP: one process, Caddy-based, binds Railway's $PORT.
FROM dunglas/frankenphp:php8.4 AS prod

RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        pdo_sqlite \
        bcmath \
        intl \
        zip \
        opcache \
        pcntl

WORKDIR /app

COPY --from=build /var/www/html /app
COPY docker/prod/Caddyfile /etc/caddy/Caddyfile
COPY docker/prod/start-container.sh /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false

EXPOSE 8080

CMD ["start-container"]

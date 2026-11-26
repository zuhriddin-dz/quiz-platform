#!/usr/bin/env bash
# Dev-only container bootstrap. Does nothing clever: makes a fresh clone
# runnable without a dozen manual `exec` calls. Never used in production.
set -euo pipefail

cd /var/www/html

if [ ! -f .env ] && [ -f .env.example ]; then
    echo "[entrypoint] creating .env from .env.example"
    cp .env.example .env
fi

if [ ! -d vendor ]; then
    echo "[entrypoint] vendor/ missing — composer install"
    composer install --no-interaction --prefer-dist
fi

if [ ! -d node_modules ]; then
    echo "[entrypoint] node_modules/ missing — npm install"
    npm install
fi

if [ -f artisan ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    echo "[entrypoint] generating APP_KEY"
    php artisan key:generate --no-interaction || true
fi

exec "$@"

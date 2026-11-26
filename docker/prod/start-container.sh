#!/bin/sh
# Production entrypoint (Railway).
#
# Caches are built HERE, not in the image, so they capture Railway's runtime
# environment rather than build-time placeholders.
#
# Migrations are deliberately NOT run here — they belong in Railway's
# Pre-Deploy Command, so a failed migration aborts the deploy instead of
# booting a half-migrated app. See PLAN.md → Deployment, step 4.
set -e

echo "[start] caching config, routes and views"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[start] launching FrankenPHP on port ${PORT:-8080}"
exec frankenphp run --config /etc/caddy/Caddyfile

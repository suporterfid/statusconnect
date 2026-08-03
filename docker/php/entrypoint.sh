#!/bin/sh
set -e

for dir in \
    storage \
    storage/app \
    storage/app/public \
    storage/framework \
    storage/framework/cache \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
do
    mkdir -p "$dir" 2>/dev/null || true
    chown -R www-data:www-data "$dir" 2>/dev/null || chmod -R 775 "$dir" 2>/dev/null || true
done

exec "$@"

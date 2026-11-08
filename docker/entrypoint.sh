#!/bin/sh
set -e
# Hosts such as Render/Railway tell us which port to listen on.
PORT="${PORT:-8080}"
sed -ri "s/Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/\*:80>/*:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# One-off: set DEMO_RESET=1 on the host to wipe this DEMO database and seed it fresh, then remove the variable.
if [ "${DEMO_RESET:-}" = "1" ]; then
    php artisan migrate:fresh --force
fi

php artisan migrate --force
php artisan db:seed --force          # idempotent: tanks, admin login, sample movements
php artisan config:cache
php artisan route:cache
exec "$@"

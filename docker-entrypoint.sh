#!/bin/sh
# =============================================================================
# Container entrypoint (CPI API)
#
# Runs for every container built from this image, so the boot work is guarded
# by environment switches:
#
#   RUN_MIGRATIONS=true|false   migrate + seed roles/accounts (web only)
#
# Anything passed as arguments is exec'd instead of the default command, which is
# how `command: [...]` in docker-compose works (queue:work, schedule:work, ...).
# =============================================================================
set -e

cd /var/www

# --- Writable runtime directories --------------------------------------------
# Recreated defensively: if storage/ is ever bind-mounted, its ownership comes
# from the host and has to be fixed here rather than at build time.
mkdir -p \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache/data \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# --- Schema ------------------------------------------------------------------
# Only one container should migrate. Worker containers (if any) must start with
# RUN_MIGRATIONS=false so they can't race the web container.
if [ "${RUN_MIGRATIONS}" = "true" ]; then
    # Wait for Postgres — on a cold `docker compose up` the DB is still starting.
    i=0
    until php artisan db:monitor > /dev/null 2>&1 || [ "$i" -ge 30 ]; do
        i=$((i + 1))
        echo "waiting for database... ($i/30)"
        sleep 2
    done

    php artisan migrate --force --no-interaction

    # Roles, permissions and the built-in staff accounts. The seeders are
    # idempotent (firstOrCreate/syncPermissions), so this is safe on every boot
    # and REQUIRED on the first one — without roles nobody can log in.
    php artisan db:seed --force --no-interaction
fi

# --- Caches ------------------------------------------------------------------
# `optimize` writes the config, route, event and view caches in one pass. It runs
# at start (not build) time because config:cache freezes the environment, and the
# environment only exists here.
php artisan optimize:clear > /dev/null 2>&1 || true
php artisan optimize

# --- Hand off ----------------------------------------------------------------
# `supervisord ...` (the image CMD) starts nginx + php-fpm; a compose override
# such as `php artisan queue:work` is exec'd directly instead.
if [ "$1" = "supervisord" ]; then
    exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
fi

exec "$@"

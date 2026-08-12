# =============================================================================
# CPI API — production image
#
#   nginx (edge) ──unix socket──> php-fpm (app) ──> Postgres / Redis / R2
#
# Both stages start from the SAME php:8.3-fpm tag on purpose: the extensions are
# compiled in the builder and copied into the runtime, which is only ABI-safe
# when the PHP build is identical. That keeps compilers out of the final image.
# =============================================================================


# =============================================================================
# Stage 1 — builder: compile PHP extensions, install Composer dependencies
# =============================================================================
FROM php:8.3-fpm-bookworm AS builder

# Build-time only: headers and compilers for the extensions below.
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    libzip-dev \
    libpq-dev \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Extensions the app needs:
#   pdo_pgsql — PostgreSQL (source de vérité unique)
#   zip       — archives (composer, exports)
#   bcmath    — montants precis (decimal 15,2 : demandes, décaissements)
#   pcntl     — signal handling for queue workers
#   opcache   — bytecode cache (enabled by opcache.ini below)
RUN docker-php-ext-install -j"$(nproc)" pdo pdo_pgsql zip bcmath pcntl opcache

# phpredis from source: cache, sessions and (when enabled) queues. Compiled
# rather than PECL'd so the build is pinned to what git ships and reproducible.
RUN git clone --depth 1 https://github.com/phpredis/phpredis.git /tmp/phpredis \
    && docker-php-ext-configure /tmp/phpredis \
    && docker-php-ext-install /tmp/phpredis \
    && rm -rf /tmp/phpredis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependency layer first: it only rebuilds when composer.json/lock change, so
# ordinary code edits reuse the cached vendor install.
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .

RUN composer dump-autoload --optimize --no-dev


# =============================================================================
# Stage 2 — runtime: nginx + php-fpm, no compilers
# =============================================================================
FROM php:8.3-fpm-bookworm

# Runtime shared libraries only (the -dev headers stayed in the builder), plus
# nginx and supervisor. curl is used by the container healthcheck.
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    curl \
    ca-certificates \
    libpq5 \
    libzip4 \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf

# Compiled extensions + their enabling ini files, straight from the builder.
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# --- Configuration -----------------------------------------------------------
# php.ini    — limits, error handling, upload sizes (médias chantier: 50 Mo)
# opcache.ini— bytecode cache (the single biggest PHP throughput win)
# www.conf   — php-fpm pool: worker sizing, `clear_env = no`, timeouts
# nginx.conf — worker/event tuning, logging to the container's stdout
# app.conf   — the API server block (front controller, FastCGI)
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/app.conf /etc/nginx/conf.d/app.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# --- Worker sizing -----------------------------------------------------------
# Defaults suit a 2 vCPU / 4 GB VPS. This API is short-request CRUD (no
# streaming), so ~64 MB per worker is typical; size by memory:
# PHP_FPM_MAX_CHILDREN ≈ (RAM available to PHP) / ~96 MB to stay safe.
# php-fpm interpolates these into www.conf, so they must always be set.
ENV PHP_FPM_MAX_CHILDREN=12 \
    PHP_FPM_START_SERVERS=4 \
    PHP_FPM_MIN_SPARE_SERVERS=2 \
    PHP_FPM_MAX_SPARE_SERVERS=6 \
    PHP_FPM_MAX_REQUESTS=500

# --- Process switches --------------------------------------------------------
# The web container runs nginx + php-fpm only. CPI has no queue jobs or
# scheduled tasks yet — flip these to true (or add compose services) the day
# notifications/e-mails move to a queue.
ENV RUN_QUEUE_WORKER=false \
    RUN_SCHEDULER=false \
    RUN_MIGRATIONS=true \
    RUN_SEEDERS=false

WORKDIR /var/www

COPY --from=builder /app /var/www

# Directories Laravel writes to at runtime, plus the php-fpm socket directory.
RUN mkdir -p \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/cache/data \
        storage/logs \
        bootstrap/cache \
        /run/php \
        /var/log/supervisor \
    && chown -R www-data:www-data /var/www /run/php \
    && chmod -R ug+rwx storage bootstrap/cache

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

# Laravel's built-in health route (bootstrap/app.php: health '/up').
# Kept cheap: it does not touch the database.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up > /dev/null || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]

# Overridable: `command:` in compose (e.g. queue:work) replaces this and the
# entrypoint execs it instead of the web stack.
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

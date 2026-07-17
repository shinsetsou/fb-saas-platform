# syntax=docker/dockerfile:1.6
# ---------- build front-end assets ----------
FROM node:20-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY vite.config.js tailwind.config.js postcss.config.js ./
RUN npm run build


# ---------- install PHP deps ----------
# Use a full PHP image (not the bare composer image) so we have bcmath
# available, which moneyphp/money (a transitive dep of laravel/cashier)
# requires at install time. We only need the CLI here.
FROM php:8.2-cli-alpine AS vendor

RUN apk add --no-cache \
        git \
        unzip \
    && docker-php-ext-install bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
# Install dev deps too — this image is meant for dev/test, and removing them
# would break `php artisan test`, PHPUnit, and dev-only service providers
# that Laravel's package discovery still references.
RUN composer install \
    --no-scripts --no-autoloader --no-interaction --prefer-dist


# ---------- runtime image ----------
FROM php:8.2-fpm-alpine AS runtime

# System packages: nginx-style deps, build tools for PHP extensions, and
# caddy for the webserver image (we use a separate image for that — see
# docker-compose.yml). git/unzip are needed by composer at install time.
RUN apk add --no-cache \
        bash \
        ca-certificates \
        git \
        icu-dev \
        libpng-dev \
        libzip-dev \
        oniguruma-dev \
        shadow \
        unzip \
        $PHPIZE_DEPS \
    && docker-php-ext-install \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/* /var/cache/apk/*

# Sensible PHP production defaults; dev-friendly overrides go in .env.docker
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf  /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/opcache-recommended.ini /usr/local/etc/php/conf.d/opcache-recommended.ini

# Composer (use the official image's copy)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Non-root user that owns the app
ARG APP_UID=1000
ARG APP_GID=1000
RUN groupadd --gid ${APP_GID} app \
    && useradd  --uid ${APP_UID} --gid ${APP_GID} --shell /bin/bash --home /home/app app

WORKDIR /var/www/html

# Copy the app source. Order matters for layer caching:
#   1. composer.* → install
#   2. public/, app/, etc. → copy
#   3. built front-end assets from the "frontend" stage
COPY --chown=app:app composer.json composer.lock ./
COPY --chown=app:app --from=vendor /app/vendor ./vendor
COPY --chown=app:app . .
COPY --chown=app:app --from=frontend /app/public/build ./public/build

# Generate optimized autoloader (idempotent; safe to redo on every build).
# No --classmap-authoritative so that test-only classes (e.g. PHPUnit's
# MockArraySessionHandler) still autoload at runtime.
RUN composer dump-autoload --no-scripts

# Storage and bootstrap cache must be writable
RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Drop host-generated package/service caches that may reference dev-only
# providers (Pail, Sail, Pint). Laravel rebuilds them on first boot.
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/routes-v7.php

COPY --chown=app:app docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

USER app

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm"]

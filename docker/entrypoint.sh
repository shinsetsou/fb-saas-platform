#!/usr/bin/env bash
# Container entrypoint. Runs as the unprivileged `app` user.
# - Waits for the database if DB_CONNECTION is mysql/pgsql.
# - Generates APP_KEY if missing.
# - Runs migrations and caches config on first start for fpm / queue /
#   scheduler. For test / artisan one-off commands it stays out of the way.
# - Execs the command passed via CMD (set in Dockerfile or compose).

set -euo pipefail

cd /var/www/html

# Make sure .env exists. We don't write secrets into the image, so on
# first boot we expect the user to copy .env.docker -> .env (or mount one).
# If APP_KEY is set, we're good; if not, we generate it.
if [[ ! -f .env ]]; then
    if [[ -f .env.docker ]]; then
        cp .env.docker .env
    else
        echo "[entrypoint] no .env and no .env.docker — refusing to start." >&2
        echo "[entrypoint] copy .env.example to .env and set the required values." >&2
        exit 1
    fi
fi

# Make sure storage and bootstrap cache are writable (the bind mount may
# have created them as root).
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

# Drop host-generated package/service caches that may reference dev-only
# providers (Pail, Sail, Pint). The host may have run with dev deps
# installed; the runtime image may not, so cached discovery would point
# at non-existent classes. Laravel rebuilds these on first request.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/routes-v7.php 2>/dev/null || true

# Pull APP_KEY (and any other env we need) from the .env file so the rest
# of this script can see them, but don't pollute the shell with the full
# file (we'll `set -a; source .env; set +a` once, then re-source to refresh
# after key generation).
set -a
# shellcheck disable=SC1091
source .env
set +a

generate_key_if_missing() {
    if [[ -z "${APP_KEY:-}" || "${APP_KEY}" == "base64:" ]]; then
        echo "[entrypoint] generating APP_KEY"
        php artisan key:generate --force --no-interaction >/dev/null
        # Re-source .env so the freshly generated key is in scope.
        set -a
        # shellcheck disable=SC1091
        source .env
        set +a
    fi
}

wait_for_db() {
    if [[ "${DB_CONNECTION:-sqlite}" == "sqlite" ]]; then
        return 0
    fi

    local host="${DB_HOST:-mysql}"
    local port="${DB_PORT:-3306}"
    local timeout="${DB_WAIT_TIMEOUT:-60}"
    local elapsed=0

    echo "[entrypoint] waiting for database ${host}:${port} (up to ${timeout}s)"
    until php -r "
        try {
            new PDO(
                'mysql:host=${host};port=${port}',
                '${DB_USERNAME:-root}',
                '${DB_PASSWORD:-}',
                [PDO::ATTR_TIMEOUT => 2]
            );
            exit(0);
        } catch (Throwable \$e) {
            exit(1);
        }
    " >/dev/null 2>&1; do
        sleep 1
        elapsed=$((elapsed + 1))
        if (( elapsed >= timeout )); then
            echo "[entrypoint] database did not become ready in ${timeout}s — aborting" >&2
            exit 1
        fi
    done
    echo "[entrypoint] database is ready"
}

maybe_migrate() {
    # Only run migrations for the long-running services; skip one-offs and tests.
    case "$1" in
        php-fpm|queue|scheduler)
            if [[ "${RUN_MIGRATIONS:-true}" == "true" ]]; then
                echo "[entrypoint] running migrations"
                php artisan migrate --force --no-interaction
            fi
            ;;
    esac
}

maybe_warm_caches() {
    case "$1" in
        php-fpm|queue|scheduler)
            if [[ "${APP_ENV:-production}" != "local" && "${WARM_CACHES:-true}" == "true" ]]; then
                php artisan config:cache >/dev/null
                php artisan route:cache  >/dev/null
                php artisan view:cache   >/dev/null
            fi
            ;;
    esac
}

generate_key_if_missing
wait_for_db

# If the command being exec'd is the test runner, force APP_ENV=testing
# in the OS env AFTER the .env has been sourced. The phpunit.xml <env>
# tags run too late — they set OS env, but Laravel's LoadEnvironmentVariables
# uses the mutable Dotenv repository, which overwrites OS env with .env's
# value. We re-apply APP_ENV here so it wins.
case "$1" in
    vendor/bin/phpunit|phpunit)
        export APP_ENV=testing
        ;;
esac

# The long-running service role (php-fpm / queue / scheduler) is set via
# the CONTAINER_ROLE env var in docker-compose.yml. For one-off `docker
# compose run app php artisan ...` invocations, CONTAINER_ROLE is still
# inherited from the service (default: php-fpm), so we additionally look
# at the first command arg: if it's not "php-fpm", we're in one-off mode
# and should skip migrations and cache warm-up.
role="${CONTAINER_ROLE:-artisan}"
if [[ "${1:-}" != "php-fpm" && "${1:-}" != "queue" && "${1:-}" != "scheduler" ]]; then
    role="artisan"
fi

maybe_migrate "$role"
maybe_warm_caches "$role"

# Clear caches if we're in local dev (config:cache would otherwise mask .env edits).
if [[ "${APP_ENV:-production}" == "local" ]]; then
    php artisan config:clear >/dev/null 2>&1 || true
    php artisan route:clear  >/dev/null 2>&1 || true
    php artisan view:clear   >/dev/null 2>&1 || true
fi

exec "$@"

#!/bin/bash
# Gäld container entrypoint (shared by the web and worker containers).
set -euo pipefail

cd /var/www/html

ROLE="${GAELD_ROLE:-web}"
mkdir -p /run/nginx /run/php

echo "[entrypoint] role=${ROLE}  APP_ENV=${APP_ENV:-?}  APP_URL=${APP_URL:-?}"

if [ -z "${APP_KEY:-}" ]; then
  echo "[entrypoint] FATAL: APP_KEY is not set" >&2
  exit 1
fi

# --- wait for PostgreSQL ---------------------------------------------------
db_host="${DB_HOST:-pgsql}"; db_port="${DB_PORT:-5432}"
echo "[entrypoint] waiting for postgres at ${db_host}:${db_port} ..."
for i in $(seq 1 60); do
  if php -r '$h=getenv("DB_HOST")?:"pgsql";$p=(int)(getenv("DB_PORT")?:5432);exit(@fsockopen($h,$p,$e,$s,2)?0:1);'; then
    echo "[entrypoint] postgres is up"; break
  fi
  [ "$i" = "60" ] && { echo "[entrypoint] FATAL: postgres never came up" >&2; exit 1; }
  sleep 2
done

php artisan config:clear >/dev/null 2>&1 || true

if [ "$ROLE" = "web" ]; then
  # gaeld:install is idempotent: always migrates, seeds org + Swiss COA only
  # on first run (returns early once an Organization exists).
  if [ "${GAELD_AUTO_INSTALL:-true}" = "true" ]; then
    echo "[entrypoint] running gaeld:install --no-interaction"
    php artisan gaeld:install --no-interaction || {
      echo "[entrypoint] gaeld:install failed — falling back to migrate --force" >&2
      php artisan migrate --force
    }
  fi
  php artisan storage:link >/dev/null 2>&1 || true
fi

# Rebuild caches from the current environment.
php artisan config:cache  >/dev/null 2>&1 || true
php artisan route:cache   >/dev/null 2>&1 || true
php artisan view:cache    >/dev/null 2>&1 || true

echo "[entrypoint] handing off to: $*"
exec "$@"

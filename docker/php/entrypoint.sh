#!/usr/bin/env bash
# Entrypoint for all Laravel containers (api, init, queue, scheduler).
#
# ROLE=init      -> install deps, ensure APP_KEY, run migrations, exit 0
# ROLE=queue     -> run the queue worker
# ROLE=scheduler -> run the scheduler
# (default)      -> php-fpm
set -euo pipefail

cd /var/www/html

wait_for_db() {
  local host="${DB_HOST:-mysql}" port="${DB_PORT:-3306}" tries=60
  echo "[entrypoint] waiting for MySQL at ${host}:${port} ..."
  until php -r "try { new PDO('mysql:host=${host};port=${port}', getenv('DB_USERNAME'), getenv('DB_PASSWORD')); exit(0);} catch (Throwable \$e) { exit(1);}" >/dev/null 2>&1; do
    tries=$((tries - 1))
    if [ "$tries" -le 0 ]; then echo "[entrypoint] MySQL not reachable, giving up"; exit 1; fi
    sleep 2
  done
  echo "[entrypoint] MySQL is up"
}

wait_for_vendor() {
  local tries=120
  until [ -f vendor/autoload.php ] && [ -f .env ]; do
    tries=$((tries - 1))
    if [ "$tries" -le 0 ]; then echo "[entrypoint] vendor/.env never appeared, giving up"; exit 1; fi
    sleep 2
  done
}

case "${ROLE:-fpm}" in
  init)
    if [ ! -f .env ]; then
      echo "[entrypoint] creating api/.env from .env.example"
      cp .env.example .env
    fi
    if [ ! -f vendor/autoload.php ]; then
      echo "[entrypoint] composer install"
      composer install --no-interaction --prefer-dist --no-progress
    fi
    if ! grep -qE '^APP_KEY=base64:' .env; then
      echo "[entrypoint] generating APP_KEY"
      php artisan key:generate --force --no-interaction
    fi
    wait_for_db
    php artisan migrate --force --no-interaction
    php artisan storage:link >/dev/null 2>&1 || true
    php artisan optimize:clear >/dev/null 2>&1 || true
    echo "[entrypoint] init done"
    exit 0
    ;;
  queue)
    wait_for_vendor; wait_for_db
    # QUEUE_NAMES: "default" = mail (sync, send); "ai" = local model work (index,
    # categories, style) in its own worker, so indexing runs while mail is still fetched.
    exec php artisan queue:work --queue="${QUEUE_NAMES:-default}" --tries=3 --backoff=10 --timeout=600 --sleep=2 --max-jobs=200 --max-time=3600
    ;;
  scheduler)
    wait_for_vendor; wait_for_db
    exec php artisan schedule:work
    ;;
  *)
    wait_for_vendor; wait_for_db
    exec "$@"
    ;;
esac

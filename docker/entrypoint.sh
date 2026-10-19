#!/bin/sh
# Точка входа образа приложения.
# RUN_MIGRATIONS=1 (сервис app): дождаться БД, применить миграции и начальные данные, затем запустить процесс.
set -eu

cd /var/www/html

if [ "${APP_ENV:-production}" = "production" ] && [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY не задан. Сгенерируйте: docker compose run --rm app console key:generate" >&2
    exit 1
fi

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    attempt=0
    until console migrate; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 30 ]; then
            echo "Не удалось применить миграции за $attempt попыток" >&2
            exit 1
        fi
        echo "База данных ещё не готова, повтор через 2 секунды ($attempt/30)…"
        sleep 2
    done
    console seed
fi

exec "$@"

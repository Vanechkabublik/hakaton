#!/bin/sh
# Запуск консольных команд приложения от пользователя www-data,
# чтобы создаваемые файлы (логи, бэкапы, загрузки) оставались доступны php-fpm.
# Использование: docker compose exec app console migrate
cd /var/www/html || exit 1
exec su-exec www-data php bin/console "$@"

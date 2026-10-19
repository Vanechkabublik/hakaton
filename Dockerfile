# ---------------------------------------------------------------------------------------------
# Стадия 1. Зависимости Composer (без dev-пакетов, с оптимизированным автозагрузчиком)
# ---------------------------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --ignore-platform-reqs
COPY src ./src
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --ignore-platform-reqs

# ---------------------------------------------------------------------------------------------
# Стадия 2. Рабочий образ: один и тот же образ запускается как php-fpm (app) и как воркер (worker)
# ---------------------------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS app

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

# fcgi — для healthcheck php-fpm; postgresql-client — pg_dump для бэкапов; su-exec — запуск консоли от www-data
RUN apk add --no-cache fcgi postgresql-client su-exec tzdata \
    && install-php-extensions pdo_pgsql gd intl opcache pcntl zip bcmath gmp

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/console.sh /usr/local/bin/console

COPY bin ./bin
COPY config ./config
COPY migrations ./migrations
COPY public ./public
COPY routes ./routes
COPY src ./src
COPY templates ./templates
COPY composer.json ./composer.json
COPY --from=vendor /app/vendor ./vendor

RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/console bin/console \
    && mkdir -p storage/logs storage/mail storage/uploads storage/backups storage/cache \
    && chown -R www-data:www-data storage

EXPOSE 9000

# Именованные тома наследуют владельца каталога из образа, поэтому права на запись выставлены заранее
VOLUME ["/var/www/html/storage/uploads", "/var/www/html/storage/backups", "/var/www/html/storage/logs"]

HEALTHCHECK --interval=15s --timeout=3s --start-period=60s --retries=5 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

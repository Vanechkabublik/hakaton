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
# Стадия 2. Рабочий образ: один и тот же образ запускается как php-fpm (app) и как воркер (worker).
# PHP ставится готовыми пакетами Alpine — ничего не компилируется, сборка занимает около минуты.
# ---------------------------------------------------------------------------------------------
FROM alpine:3.21 AS app

# Группа www-data (gid 82) уже есть в базовом образе Alpine, создаём только пользователя.
# fcgi — для healthcheck php-fpm; postgresql16-client — pg_dump для бэкапов; su-exec — запуск консоли от www-data
RUN apk add --no-cache \
        php83 php83-fpm php83-opcache php83-pdo php83-pdo_pgsql php83-pgsql php83-gd php83-intl php83-zip \
        php83-bcmath php83-gmp php83-sodium php83-mbstring php83-curl php83-dom php83-openssl php83-ctype \
        php83-iconv php83-fileinfo php83-tokenizer php83-xml php83-xmlreader php83-xmlwriter php83-simplexml \
        php83-session php83-posix php83-phar php83-pcntl \
        postgresql16-client fcgi su-exec tzdata \
    && ln -sf /usr/bin/php83 /usr/bin/php \
    && ln -sf /usr/sbin/php-fpm83 /usr/sbin/php-fpm \
    && adduser -u 82 -D -S -G www-data -h /var/www www-data

WORKDIR /var/www/html

COPY docker/php/php.ini /etc/php83/conf.d/zz-app.ini
COPY docker/php/php-fpm.conf /etc/php83/php-fpm.conf
COPY docker/php/www.conf /etc/php83/php-fpm.d/www.conf
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

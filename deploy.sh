#!/usr/bin/env bash
#
# Деплой на сервер с Docker: первый запуск и обновления (скрипт идемпотентный).
#
#   ./deploy.sh                   создать .env (при первом запуске), обновить код, собрать образы, поднять стек
#   ./deploy.sh --no-pull         не делать git pull (деплой текущего состояния каталога)
#   ./deploy.sh --staging         тестовый центр сертификации Let's Encrypt (для проб; браузеры ему не доверяют)
#   ./deploy.sh --production      вернуться на боевой Let's Encrypt
#   ./deploy.sh --install-docker  установить Docker, если его нет (нужен root)
#   ./deploy.sh --behind-proxy[=8081]  порты 80/443 заняты другим сайтом: стек слушает только 127.0.0.1:8081,
#                                 а HTTPS и сертификат даёт ваш веб-сервер (nginx/Caddy/Apache) — конфиг покажем
#   ./deploy.sh --standalone      вернуть режим по умолчанию (Caddy сам на 80/443 и сам выпускает сертификат)
#
# Без вопросов:  DOMAIN=news.example.com ACME_EMAIL=me@example.com ADMIN_EMAIL=me@example.com ./deploy.sh
#
set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

DEFAULT_DOMAIN="news.vancodex.tech"
STAGING_CA="https://acme-staging-v02.api.letsencrypt.org/directory"
PRODUCTION_CA="https://acme-v02.api.letsencrypt.org/directory"

PULL=1
INSTALL_DOCKER=0
PROXY_REQUEST=""
PROXY_PORT=8081
ACME_MODE=""
FIRST_RUN=0

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m ✓\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m ! %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31m ✗ %s\033[0m\n' "$*" >&2; exit 1; }

trap 'die "Ошибка на строке $LINENO. Деплой прерван."' ERR

usage() { sed -n '2,16p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

for arg in "$@"; do
    case "$arg" in
        --no-pull) PULL=0 ;;
        --staging) ACME_MODE="staging" ;;
        --production) ACME_MODE="production" ;;
        --install-docker) INSTALL_DOCKER=1 ;;
        --behind-proxy) PROXY_REQUEST="proxy" ;;
        --behind-proxy=*) PROXY_REQUEST="proxy"; PROXY_PORT="${arg#*=}" ;;
        --standalone) PROXY_REQUEST="standalone" ;;
        -h | --help) usage; exit 0 ;;
        *) die "Неизвестный параметр: $arg (см. ./deploy.sh --help)" ;;
    esac
done

# ---- вспомогательные функции для .env -------------------------------------------------------

env_get() {
    grep -E "^$1=" .env 2>/dev/null | tail -n1 | cut -d= -f2- || true
}

env_set() {
    local key=$1 value=$2 escaped
    escaped=$(printf '%s' "$value" | sed -e 's/[\\|&]/\\&/g')
    if grep -qE "^${key}=" .env; then
        sed -i.bak -E "s|^${key}=.*|${key}=${escaped}|" .env && rm -f .env.bak
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

# ask ПЕРЕМЕННАЯ "Вопрос" "значение по умолчанию": берёт значение из окружения, иначе спрашивает в терминале
ask() {
    local var=$1 prompt=$2 default=$3 reply=""
    if [[ -n "${!var:-}" ]]; then
        return
    fi
    if [[ -t 0 ]]; then
        read -r -p "$prompt${default:+ [$default]}: " reply
    fi
    printf -v "$var" '%s' "${reply:-$default}"
}

random_hex() { openssl rand -hex "$1"; }

# ---- 1. Docker --------------------------------------------------------------------------------

log "Проверяю окружение"
command -v openssl >/dev/null 2>&1 || die "Нужен openssl (apt install openssl)"
command -v curl >/dev/null 2>&1 || die "Нужен curl (apt install curl)"

if ! command -v docker >/dev/null 2>&1; then
    if ((INSTALL_DOCKER)); then
        [[ $EUID -eq 0 ]] || die "Для установки Docker запустите от root: sudo ./deploy.sh --install-docker"
        log "Устанавливаю Docker"
        curl -fsSL https://get.docker.com | sh
        systemctl enable --now docker >/dev/null 2>&1 || true
    else
        die "Docker не найден. Установите его (https://docs.docker.com/engine/install/) или запустите ./deploy.sh --install-docker"
    fi
fi
docker compose version >/dev/null 2>&1 || die "Нужен Docker Compose v2 (команда «docker compose»)"
docker info >/dev/null 2>&1 || die "Нет доступа к Docker: запустите от root, добавьте пользователя в группу docker или стартуйте службу docker"
ok "Docker готов"

# ---- 2. Код -----------------------------------------------------------------------------------

if ((PULL)) && [[ -d .git ]] && command -v git >/dev/null 2>&1; then
    log "Обновляю код (git pull)"
    git pull --ff-only || die "git pull не удался. Исправьте конфликт или запустите ./deploy.sh --no-pull"
fi

# ---- 3. .env ----------------------------------------------------------------------------------

if [[ ! -f .env ]]; then
    FIRST_RUN=1
    log "Создаю .env (первый запуск)"
    [[ -f .env.example ]] || die "Не найден .env.example"
    cp .env.example .env
    chmod 600 .env

    ask DOMAIN "Домен сайта" "$DEFAULT_DOMAIN"
    [[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || die "Некорректный домен: $DOMAIN"
    ask ACME_EMAIL "Email для Let's Encrypt (уведомления о сертификате)" ""
    [[ "$ACME_EMAIL" =~ ^[^@[:space:]\|\&\\]+@[^@[:space:]\|\&\\]+\.[^@[:space:]\|\&\\]+$ ]] || die "Укажите корректный ACME_EMAIL (переменная окружения или ответ на вопрос)"
    ask ADMIN_EMAIL "Email администратора сайта" "$ACME_EMAIL"
    [[ "$ADMIN_EMAIL" =~ ^[^@[:space:]\|\&\\]+@[^@[:space:]\|\&\\]+\.[^@[:space:]\|\&\\]+$ ]] || die "Некорректный ADMIN_EMAIL"

    env_set DOMAIN "$DOMAIN"
    env_set APP_URL "https://$DOMAIN"
    env_set ACME_EMAIL "$ACME_EMAIL"
    env_set CONTACT_EMAIL "$ADMIN_EMAIL"
    env_set ADMIN_EMAIL "$ADMIN_EMAIL"
    env_set ADMIN_PASSWORD "$(random_hex 10)Aa1"
    env_set DB_PASSWORD "$(random_hex 24)"
    env_set APP_KEY "base64:$(openssl rand -base64 32)"
    ok ".env создан, секреты сгенерированы"
fi

for key in DOMAIN ACME_EMAIL APP_KEY DB_PASSWORD; do
    [[ -n "$(env_get "$key")" ]] || die "В .env не задан $key (см. .env.example)"
done
DOMAIN="$(env_get DOMAIN)"

case "$ACME_MODE" in
    staging)
        env_set ACME_CA "$STAGING_CA"
        warn "Включён ТЕСТОВЫЙ Let's Encrypt: сертификат не будет доверенным в браузерах. Для боевого — ./deploy.sh --production"
        ;;
    production) env_set ACME_CA "$PRODUCTION_CA" ;;
esac
STAGING=0
[[ "$(env_get ACME_CA)" == "$STAGING_CA" ]] && STAGING=1

# ---- 3a. Режим работы: сам на 80/443 или за вашим веб-сервером ------------------------------------

[[ "$PROXY_PORT" =~ ^[0-9]{2,5}$ ]] || die "Некорректный порт в --behind-proxy: $PROXY_PORT"
case "$PROXY_REQUEST" in
    proxy)
        env_set CADDYFILE "Caddyfile.proxy"
        env_set HTTP_BIND "127.0.0.1:$PROXY_PORT"
        env_set HTTPS_BIND "127.0.0.1:"
        ;;
    standalone)
        env_set CADDYFILE "Caddyfile"
        env_set HTTP_BIND "80"
        env_set HTTPS_BIND "443"
        ;;
esac
BEHIND_PROXY=0
[[ "$(env_get CADDYFILE)" == "Caddyfile.proxy" ]] && BEHIND_PROXY=1
if ((BEHIND_PROXY)); then
    PROXY_PORT="$(env_get HTTP_BIND)"
    PROXY_PORT="${PROXY_PORT##*:}"
    ok "Режим «за вашим веб-сервером»: стек слушает 127.0.0.1:$PROXY_PORT"
fi

# ---- 4. DNS -----------------------------------------------------------------------------------

if [[ "$DOMAIN" != "localhost" && "$DOMAIN" != *.localhost ]]; then
    resolved="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk 'NR==1 {print $1}' || true)"
    server_ip="$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || true)"
    if [[ -z "$resolved" ]]; then
        warn "Домен $DOMAIN пока не резолвится. Создайте A-запись на IP этого сервера${server_ip:+ ($server_ip)} — иначе сертификат не выдастся."
    elif [[ -n "$server_ip" && "$resolved" != "$server_ip" ]]; then
        warn "Домен $DOMAIN указывает на $resolved, а IP этого сервера — $server_ip. Проверьте DNS (или это прокси/NAT)."
    else
        ok "DNS: $DOMAIN → $resolved"
    fi
fi

# ---- 4a. Порты 80/443 свободны ----------------------------------------------------------------

# Порт занят, если к нему удаётся подключиться (встроенными средствами bash, без ss/netstat)
port_busy() { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null; }

port_owner() {
    if command -v ss >/dev/null 2>&1; then
        ss -ltnp "sport = :$1" 2>/dev/null | tail -n +2
    elif command -v lsof >/dev/null 2>&1; then
        lsof -nP -iTCP:"$1" -sTCP:LISTEN 2>/dev/null | tail -n +2
    fi
}

# Если наш Caddy уже запущен (повторный деплой) — порты заняты им самим, это нормально
if ! docker compose ps --status running --services 2>/dev/null | grep -qx caddy; then
    busy=0
    http_bind="$(env_get HTTP_BIND)"
    https_bind="$(env_get HTTPS_BIND)"
    for bind in "${http_bind:-80}" "${https_bind:-443}"; do
        port="${bind##*:}"
        [[ -n "$port" ]] || continue # «127.0.0.1:» — порт назначит Docker
        if port_busy "$port"; then
            warn "Порт $port уже занят на этом сервере:"
            port_owner "$port" >&2 || true
            busy=1
        fi
    done
    if ((busy)); then
        if ((BEHIND_PROXY)); then
            die "Порт занят — выберите другой: ./deploy.sh --behind-proxy=8082"
        fi
        die "Порты 80/443 заняты другим сайтом. Запустите ./deploy.sh --behind-proxy — стек встанет за ваш веб-сервер (конфиг для него покажем). Либо освободите порты и запустите ./deploy.sh снова."
    fi
fi

# ---- 5. Сборка и запуск -----------------------------------------------------------------------

log "Собираю образы"
docker compose build --pull

log "Запускаю стек (миграции и начальные данные применяются автоматически)"
if ! docker compose up -d --remove-orphans --wait --wait-timeout 240; then
    docker compose ps || true
    docker compose logs --tail=60 app db || true
    die "Сервисы не стали healthy. Логи выше; подробнее: docker compose logs -f"
fi
ok "Сервисы запущены"

docker image prune -f >/dev/null 2>&1 || true

# ---- 6. Проверка HTTPS ------------------------------------------------------------------------

if ((BEHIND_PROXY)); then
    status="000"
    for _ in $(seq 1 15); do
        status="$(curl -sS --max-time 8 -o /dev/null -w '%{http_code}' -H "Host: $DOMAIN" "http://127.0.0.1:$PROXY_PORT/" 2>/dev/null || true)"
        [[ "$status" == "200" ]] && break
        sleep 2
    done
    if [[ "$status" == "200" ]]; then
        ok "Сайт отвечает на 127.0.0.1:$PROXY_PORT (200). Осталось направить на него домен в вашем веб-сервере."
    else
        warn "127.0.0.1:$PROXY_PORT пока не отвечает (код $status). Логи: docker compose logs caddy app"
    fi
elif [[ "$DOMAIN" != "localhost" && "$DOMAIN" != *.localhost ]]; then
    log "Жду выпуска SSL-сертификата для $DOMAIN (обычно до минуты)"
    curl_args=(-sS --max-time 8 -o /dev/null -w '%{http_code}')
    ((STAGING)) && curl_args+=(-k)
    status="000"
    for _ in $(seq 1 30); do
        status="$(curl "${curl_args[@]}" "https://$DOMAIN/" 2>/dev/null || true)"
        [[ "$status" == "200" ]] && break
        sleep 4
    done
    if [[ "$status" == "200" ]]; then
        ok "https://$DOMAIN/ отвечает 200, сертификат выдан"
    else
        warn "https://$DOMAIN/ пока недоступен (код $status). Проверьте DNS, порты 80/443 и логи: docker compose logs caddy"
    fi
fi

# ---- 7. Итоги ---------------------------------------------------------------------------------

echo
docker compose ps
echo
if ((BEHIND_PROXY)); then
    ok "Стек готов на 127.0.0.1:$PROXY_PORT. Чтобы сайт открылся по https://$DOMAIN/, добавьте домен в ваш веб-сервер:"
    cat <<PROXYHELP

  ── nginx: /etc/nginx/sites-available/$DOMAIN (+ ссылка в sites-enabled), затем «nginx -t && systemctl reload nginx»
     и «certbot --nginx -d $DOMAIN» (выпустит сертификат и включит HTTPS):

     server {
         listen 80;
         server_name $DOMAIN;
         client_max_body_size 20m;
         location / {
             proxy_pass http://127.0.0.1:$PROXY_PORT;
             proxy_set_header Host \$host;
             proxy_set_header X-Forwarded-For \$remote_addr;
             proxy_set_header X-Forwarded-Proto \$scheme;
             proxy_read_timeout 300s;
         }
     }

  ── Caddy (если на сервере другой Caddy): добавьте в его Caddyfile и перезагрузите — сертификат он выпустит сам:

     $DOMAIN {
         reverse_proxy 127.0.0.1:$PROXY_PORT
     }

  ── Любой другой веб-сервер: проксируйте домен на http://127.0.0.1:$PROXY_PORT, сохраняя заголовок Host и передавая
     X-Forwarded-For (адрес посетителя) и X-Forwarded-Proto (https).

PROXYHELP
else
    ok "Готово: https://$DOMAIN/    админка: https://$DOMAIN/admin"
fi
# Пароль администратора лежит в .env, пока вы не удалите строку ADMIN_PASSWORD (показываем и при повторных запусках,
# например если первый запуск прервался на сборке образа)
ADMIN_PASSWORD_IN_ENV="$(env_get ADMIN_PASSWORD)"
if [[ -n "$ADMIN_PASSWORD_IN_ENV" ]]; then
    echo
    echo "  Администратор:  $(env_get ADMIN_EMAIL)"
    echo "  Пароль:         $ADMIN_PASSWORD_IN_ENV"
    echo "  (из файла .env — после первого входа смените пароль в кабинете и удалите строку ADMIN_PASSWORD из .env)"
fi
if ((FIRST_RUN)); then
    echo
    echo "  Дальше в админке («Настройки»): ключи ЮKassa, SMTP, Яндекс ID. Подробности — README.md"
fi

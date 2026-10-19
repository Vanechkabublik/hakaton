.DEFAULT_GOAL := help
COMPOSE ?= docker compose

.PHONY: help deploy up down restart build ps logs shell console migrate seed psql backup restore test lint config

help: ## Список команд
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "} {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

deploy: ## Обновить код и перезапустить стек на сервере
	./deploy.sh

up: ## Поднять стек
	$(COMPOSE) up -d --build --wait

down: ## Остановить стек (данные в томах сохраняются)
	$(COMPOSE) down

restart: ## Перезапустить app и worker
	$(COMPOSE) restart app worker

build: ## Собрать образ приложения
	$(COMPOSE) build

ps: ## Состояние сервисов
	$(COMPOSE) ps

logs: ## Логи всех сервисов (хвост)
	$(COMPOSE) logs -f --tail=100

shell: ## Оболочка в контейнере приложения (от www-data)
	$(COMPOSE) exec -u www-data app sh

console: ## Консольная команда: make console ARGS="user:create-admin me@example.com"
	$(COMPOSE) exec app console $(ARGS)

migrate: ## Применить миграции
	$(COMPOSE) exec app console migrate

seed: ## Начальные данные (рубрики, тарифы, страницы, администратор)
	$(COMPOSE) exec app console seed

psql: ## Консоль PostgreSQL
	$(COMPOSE) exec db sh -c 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'

backup: ## Дамп БД в storage/backups (том backups) и копия в ./backups
	$(COMPOSE) exec app console backup
	mkdir -p backups
	$(COMPOSE) exec -T app sh -c 'ls -t storage/backups/*.sql.gz | head -n1 | xargs cat' > backups/db-$$(date +%Y%m%d-%H%M%S).sql.gz
	@ls -lh backups | tail -n 3

restore: ## Восстановить БД из дампа: make restore FILE=backups/db-....sql.gz
	@test -n "$(FILE)" || (echo "Укажите FILE=путь/к/дампу.sql.gz" && exit 1)
	@echo "Внимание: данные в базе будут перезаписаны. Ctrl+C — отмена, Enter — продолжить." && read _
	$(COMPOSE) stop app worker
	gunzip -c "$(FILE)" | $(COMPOSE) exec -T db sh -c 'psql -v ON_ERROR_STOP=1 -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'
	$(COMPOSE) up -d --wait

test: ## Тесты (нужны локальный PHP 8.3 и PostgreSQL, см. phpunit.xml)
	vendor/bin/phpunit

lint: ## Проверка синтаксиса PHP
	composer lint

config: ## Проверка и вывод итоговой конфигурации compose
	$(COMPOSE) config

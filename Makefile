.PHONY: help up down restart logs ps build test test-mail lint shell tinker sync remove-account reset

help:
	@echo "Sovera Mail"
	@echo "  make up            start API, MySQL, queue, scheduler and the Nuxt dev server (Docker)"
	@echo "  make down          stop everything"
	@echo "  make logs          follow logs"
	@echo "  make test          run the Laravel test suite"
	@echo "  make test-mail     run it against a throwaway IMAP/SMTP server (GreenMail)"
	@echo "  make lint          run Pint (PHP code style)"
	@echo "  make shell         shell inside the API container"
	@echo "  make sync          run an incremental sync for all accounts now"
	@echo "  make remove-account EMAIL=x@gmail.com [KEEP=1]   remove a mailbox's LOCAL copy, or with KEEP=1 just disconnect (Gmail untouched)"
	@echo "  make reset         DROP the local database volume (deletes synced mail)"

up:
	docker compose up -d --build
	@echo "API:  http://localhost:$${API_PORT_HOST:-8090}/api/health"
	@echo "Web:  http://localhost:$${WEB_PORT_HOST:-3000}"

down:
	docker compose down

restart:
	docker compose restart api queue queue-ai scheduler

logs:
	docker compose logs -f --tail=100

ps:
	docker compose ps

build:
	docker compose build

test:
	docker compose run --rm --no-deps --entrypoint sh api -c "php artisan test"

# Integration tests against a real IMAP/SMTP server. Without this the live
# tests skip themselves, so plain `make test` stays fast and offline.
test-mail:
	docker compose --profile mailtest up -d greenmail
	docker compose run --rm --entrypoint sh api -c "php artisan test"
	docker compose --profile mailtest down greenmail

lint:
	docker compose run --rm --no-deps --entrypoint sh api -c "vendor/bin/pint"

shell:
	docker compose exec api bash

tinker:
	docker compose exec api php artisan tinker

sync:
	docker compose exec api php artisan sovera:sync-accounts --now

# Same as "Remove account" in the app. KEEP=1 = disconnect but keep the local mail.
remove-account:
	@test -n "$(EMAIL)" || (echo "usage: make remove-account EMAIL=you@gmail.com [KEEP=1]" && exit 1)
	docker compose exec api php artisan sovera:remove-account $(EMAIL) --force $(if $(KEEP),--keep-mail,)

# Deletes the database volume: all synced mail, categories, the AI index. Asks first.
reset:
	@printf "This DELETES the local database (synced mail, categories, AI index). Gmail is not touched.\nType DELETE to continue: "; \
	read answer; [ "$$answer" = "DELETE" ] || { echo "Cancelled."; exit 1; }
	docker compose down -v

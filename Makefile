SHELL := /bin/zsh

.PHONY: run clean restart logs ps

run:
	docker compose up -d

clean:
	docker compose down --remove-orphans

restart: clean run

logs:
	docker compose logs -f --tail=150

ps:
	docker compose ps

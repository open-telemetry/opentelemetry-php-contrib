PHP_VERSION ?= 7.4
include .env
PROJECT ?= Aws
ROOT=/usr/src/myapp/src
DC_RUN = ${DOCKER_COMPOSE} run --rm
DC_RUN_PHP = $(DC_RUN) -w ${ROOT}/${PROJECT} php

.DEFAULT_GOAL : help

help: ## Show this help
	@echo "example: PROJECT=Aws PHP_VERSION=8.1 make <command>"
	@printf "\033[33m%s:\033[0m\n" 'Available commands'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_-]+:.*?## / {printf "  \033[32m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
all: update style packages-composer phan psalm phpstan test ## Everything
install: ## Install dependencies
	$(DC_RUN_PHP) env XDEBUG_MODE=off composer install
update: ## Update dependencies
	$(DC_RUN_PHP) env XDEBUG_MODE=off composer update
test: test-unit test-integration ## Run unit and integration tests
test-unit: ## Run unit tests
	$(DC_RUN_PHP) env XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite unit --colors=always --coverage-text --testdox --coverage-clover coverage.clover --coverage-html=tests/coverage/html
test-integration: ## Run integration tests
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/phpunit --testsuite integration  --coverage-text --testdox --colors=always
test-coverage: ## Run unit tests and generate coverage
	$(DC_RUN_PHP) env XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite unit --coverage-html=tests/coverage/html
phan: ## Run phan
	$(DC_RUN_PHP) env XDEBUG_MODE=off env PHAN_DISABLE_XDEBUG_WARN=1 vendor/bin/phan
psalm: ## Run psalm
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/psalm --threads=1 --no-cache --php-version=${PHP_VERSION}
psalm-info: ## Run psalm with info
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/psalm --show-info=true --threads=1
phpstan: ## Run phpstan
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=256M
packages-composer: ## Validate composer packages
	$(DC_RUN) php env XDEBUG_MODE=off vendor/bin/otel packages:composer:validate
bash: ## Bash shell
	$(DC_RUN_PHP) bash
style: ## Run php-cs-fixer
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --using-cache=no -vvv
split: ## git-split dry run
	docker-compose -f docker/gitsplit/docker-compose.yaml --env-file ./.env up
FORCE:

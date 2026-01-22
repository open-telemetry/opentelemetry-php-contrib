# Save variables if passed via command-line or environment before .env overrides them
_ORIG_PROJECT := $(PROJECT)
_ORIG_PHP_VERSION := $(PHP_VERSION)
-include .env
# Restore variables if passed externally, otherwise use .env value or default
ifneq ($(_ORIG_PROJECT),)
PROJECT := $(_ORIG_PROJECT)
endif
ifneq ($(_ORIG_PHP_VERSION),)
PHP_VERSION := $(_ORIG_PHP_VERSION)
endif
PROJECT ?= Aws
PHP_VERSION ?= 8.2
ROOT=/usr/src/myapp/src
DC_RUN = ${DOCKER_COMPOSE} run --rm
DC_RUN_PHP = $(DC_RUN) -w ${ROOT}/${PROJECT} php

.DEFAULT_GOAL : help

help: ## Show this help
	@echo "example: PROJECT=Aws PHP_VERSION=8.2 make <command>"
	@printf "\033[33m%s:\033[0m\n" 'Available commands'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_-]+:.*?## / {printf "  \033[32m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
all-checks: style validate phan psalm phpstan test ## All checks + tests
all: update all-checks ## Everything
all-lowest: update-lowest all-checks ## Everything, with lowest supported versions
build: ## Build image
	$(DOCKER_COMPOSE) build --build-arg PHP_VERSION=${PHP_VERSION} php
install: ## Install dependencies
	$(DC_RUN_PHP) env XDEBUG_MODE=off composer install
update: ## Update dependencies
	$(DC_RUN_PHP) env XDEBUG_MODE=off composer update --no-interaction
update-lowest: ## Update dependencies to lowest supported versions
	$(DC_RUN_PHP) env XDEBUG_MODE=off composer update --no-interaction --prefer-lowest
test: ## Run all tests
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/phpunit --testdox --colors=always
test-unit: ## Run unit tests
	$(DC_RUN_PHP) env XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite unit --colors=always --coverage-text --testdox --coverage-clover coverage.clover --coverage-html=tests/coverage/html
test-integration: ## Run integration tests
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/phpunit --testsuite integration  --testdox --colors=always
test-coverage: ## Run unit tests and generate coverage
	$(DC_RUN_PHP) env XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html=tests/coverage/html
phan: ## Run phan
	$(DC_RUN_PHP) env XDEBUG_MODE=off env PHAN_DISABLE_XDEBUG_WARN=1 vendor/bin/phan
psalm: ## Run psalm
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/psalm --threads=1 --no-cache --php-version=${PHP_VERSION}
psalm-info: ## Run psalm with info
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/psalm --show-info=true --threads=1
phpstan: ## Run phpstan
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=256M
rector: ## Run rector
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/rector
rector-dry-run: ## Run rector (dry-run)
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/rector --dry-run

# List of all packages for rector-all target
PACKAGES := Aws Context/Swoole Exporter/Instana Instrumentation/AwsSdk Instrumentation/CakePHP \
	Instrumentation/CodeIgniter Instrumentation/Curl Instrumentation/Doctrine Instrumentation/ExtAmqp \
	Instrumentation/ExtRdKafka Instrumentation/Guzzle Instrumentation/HttpAsyncClient Instrumentation/HttpConfig \
	Instrumentation/IO Instrumentation/Laravel Instrumentation/MongoDB Instrumentation/MySqli \
	Instrumentation/OpenAIPHP Instrumentation/PDO Instrumentation/PostgreSql Instrumentation/Psr3 \
	Instrumentation/Psr6 Instrumentation/Psr14 Instrumentation/Psr15 Instrumentation/Psr16 Instrumentation/Psr18 \
	Instrumentation/ReactPHP Instrumentation/Session Instrumentation/Slim Instrumentation/Symfony \
	Instrumentation/Yii Logs/Monolog Propagation/CloudTrace Propagation/Instana Propagation/ServerTiming \
	Propagation/TraceResponse ResourceDetectors/Azure ResourceDetectors/Container ResourceDetectors/DigitalOcean \
	Sampler/RuleBased Sampler/Xray Shims/OpenTracing SqlCommenter Symfony/src/OtelBundle \
	Symfony/src/OtelSdkBundle Utils/Test

rector-all: ## Run rector on all packages (with composer update)
	@for pkg in $(PACKAGES); do \
		echo "=== Running rector on $$pkg ==="; \
		$(MAKE) --no-print-directory PROJECT=$$pkg update && \
		$(MAKE) --no-print-directory PROJECT=$$pkg rector || exit 1; \
	done
validate: ## Validate composer file
	$(DC_RUN_PHP) env XDEBUG_MODE=off composer validate --no-plugins
packages-composer: ## Validate all composer packages
	$(DC_RUN) php env XDEBUG_MODE=off vendor/bin/otel packages:composer:validate
bash: ## Bash shell
	$(DC_RUN_PHP) bash
style: ## Run php-cs-fixer
	$(DC_RUN_PHP) env XDEBUG_MODE=off PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --using-cache=no -vvv
split: ## git-split dry run
	${DOCKER_COMPOSE} -f docker/gitsplit/docker-compose.yaml --env-file ./.env up
FORCE:

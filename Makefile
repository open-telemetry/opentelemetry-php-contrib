PHP_VERSION ?= 7.4
DC_RUN_PHP = docker-compose run --rm php

all: update style phan psalm phpstan test
install:
	$(DC_RUN_PHP) env XDEBUG_MODE=off composer install
update:
	$(DC_RUN_PHP) env XDEBUG_MODE=off composer update
test: test-unit test-integration
test-unit:
	$(DC_RUN_PHP) env XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite unit --colors=always --coverage-text --testdox --coverage-clover coverage.clover --coverage-html=tests/coverage/html
test-integration:
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/phpunit --testsuite integration  --coverage-text --testdox --colors=always
test-coverage:
	$(DC_RUN_PHP) env XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite unit --coverage-html=tests/coverage/html
phan:
	$(DC_RUN_PHP) env XDEBUG_MODE=off env PHAN_DISABLE_XDEBUG_WARN=1 vendor/bin/phan
psalm:
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/psalm --threads=1 --no-cache --php-version=${PHP_VERSION}
psalm-info:
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/psalm --show-info=true --threads=1
phpstan:
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/phpstan analyse
bash:
	$(DC_RUN_PHP) bash
style:
	$(DC_RUN_PHP) env XDEBUG_MODE=off vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --using-cache=no -vvv
FORCE:

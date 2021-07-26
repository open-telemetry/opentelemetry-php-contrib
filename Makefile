DC_RUN_PHP = docker-compose run --rm php

install:
	$(DC_RUN_PHP) composer install
update:
	$(DC_RUN_PHP) composer update
test:
	$(DC_RUN_PHP) php ./vendor/bin/phpunit --colors=always --coverage-text --testdox --coverage-clover coverage.clover
phan:
	$(DC_RUN_PHP) env PHAN_DISABLE_XDEBUG_WARN=1 php ./vendor/bin/phan
psalm:
	$(DC_RUN_PHP) php ./vendor/bin/psalm
psalm-info:
	$(DC_RUN_PHP) php ./vendor/bin/psalm --show-info=true
phpstan: 
	$(DC_RUN_PHP) php ./vendor/bin/phpstan analyse
bash:
	$(DC_RUN_PHP) bash
style:
	$(DC_RUN_PHP) php ./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --dry-run --stop-on-violation --using-cache=no -vvv
FORCE:

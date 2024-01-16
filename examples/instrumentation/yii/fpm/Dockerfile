ARG PHP_VERSION=8.2
FROM yiisoftware/yii2-php:${PHP_VERSION}-fpm

RUN apt upgrade

RUN apt install $PHPIZE_DEPS

RUN pecl install opentelemetry protobuf
RUN docker-php-ext-enable opentelemetry protobuf
RUN docker-php-source delete

RUN cp /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini

WORKDIR /var/www/html

RUN composer create-project --prefer-dist yiisoft/yii2-app-basic .
RUN composer require \
    open-telemetry/opentelemetry-auto-yii \
    open-telemetry/sdk \
    open-telemetry/exporter-otlp \
    php-http/guzzle7-adapter
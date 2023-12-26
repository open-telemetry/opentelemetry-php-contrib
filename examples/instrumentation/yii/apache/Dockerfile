ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-apache

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN a2enmod rewrite

RUN chmod +x /usr/local/bin/install-php-extensions \
  && install-php-extensions \
        zip \
        opentelemetry \
        protobuf \
        @composer

WORKDIR /var/www/html

RUN composer create-project --prefer-dist yiisoft/yii2-app-basic .
RUN composer require \
    open-telemetry/opentelemetry-auto-yii \
    open-telemetry/sdk \
    open-telemetry/exporter-otlp \
    php-http/guzzle7-adapter
ARG PHP_VERSION=8.0
FROM ghcr.io/open-telemetry/opentelemetry-php/opentelemetry-php-base:${PHP_VERSION}

USER root

RUN install-php-extensions \
        opentelemetry \
        mongodb \
        amqp \
        rdkafka

USER php

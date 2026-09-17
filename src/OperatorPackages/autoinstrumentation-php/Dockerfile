# Build args declared before the first FROM are usable in FROM instructions.
ARG PHP_IMAGE=php:8.1
ARG OPENTELEMETRY_VERSION=1.4.0
ARG PIE_VERSION=1.4.9
ARG PIE_SHA256=19a31ddd4bfd08b9eb5eaad2e5f63e76e7919cae7683852da41c80da704ad6c0

# Parameterized extension builder — driven by docker-bake.hcl.
FROM ${PHP_IMAGE} AS builder
ARG OPENTELEMETRY_VERSION
ARG PIE_VERSION
ARG PIE_SHA256
ARG LIBC=glibc
ARG THREAD=non-zts

WORKDIR /build/${LIBC}/${THREAD}

RUN if [ "${LIBC}" = "musl" ]; then \
      apk add autoconf build-base libtool pkgconfig unzip; \
    else \
      apt-get update && apt-get install -y zlib1g-dev libzip-dev unzip && docker-php-ext-install zip; \
    fi \
    && curl -fsSL https://github.com/php/pie/releases/download/${PIE_VERSION}/pie.phar -o /usr/local/bin/pie \
    && echo "${PIE_SHA256}  /usr/local/bin/pie" | sha256sum -c - \
    && chmod +x /usr/local/bin/pie \
    && pie install open-telemetry/ext-opentelemetry:${OPENTELEMETRY_VERSION} \
    && cp /usr/local/lib/php/extensions/no-debug-${THREAD}-*/opentelemetry.so .

COPY --from=composer:2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 /usr/bin/composer /usr/bin/composer
COPY composer.json .
RUN composer install --ignore-platform-reqs
RUN mv vendor/* . && rm -rf vendor composer.json composer.lock

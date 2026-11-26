# Build args declared before the first FROM are usable in FROM instructions.
ARG PHP_IMAGE=php:8.2@sha256:9277667c0fc298de473509dfed37adf969c97a0372338de990491b39bacf99a5
ARG OPENTELEMETRY_VERSION=1.5.0
ARG PIE_VERSION=1.6.0
ARG PIE_SHA256=81041b276f4e23dfdfa6dc941e5745f9adec824a1bcf6141e226181ef43f519e

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

COPY --from=composer:2@sha256:0049ce90b432795bf413a738b073963ada1a689e1a8708104684baafe94586ec /usr/bin/composer /usr/bin/composer
COPY composer.json .
RUN composer install --ignore-platform-reqs
RUN mv vendor/* . && rm -rf vendor composer.json composer.lock

FROM scratch AS artifact
COPY --from=builder /build /build

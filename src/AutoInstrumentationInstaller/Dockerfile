FROM composer:2.10.3@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b as composer
FROM php:8.5.10@sha256:df50257c90ad9a53052fb4e9c6fdba8262cf72ca9e9a048b7c89f74c8d23fc0d
WORKDIR /srv/app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
  && install-php-extensions \
    xdebug \
    zip

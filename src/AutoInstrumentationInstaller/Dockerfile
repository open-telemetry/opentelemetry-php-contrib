FROM composer:2@sha256:1997db9e43fde2a5a20f32fcfd50f26f698d951b3787334ebd6493d0562de97f as composer
FROM php:8.2@sha256:85237f2abcd63e3d584664f0cb398eb619aa1a205b7152cb4cb6d3e929573ad2
WORKDIR /srv/app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
  && install-php-extensions \
    xdebug \
    zip

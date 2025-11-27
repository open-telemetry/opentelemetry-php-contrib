FROM composer:2@sha256:3ca62a8176c743eebef305ac2b93094a733dd5a34b5c1e3d3cf6cbbbd0792649 as composer
FROM php:8.2@sha256:85237f2abcd63e3d584664f0cb398eb619aa1a205b7152cb4cb6d3e929573ad2
WORKDIR /srv/app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
  && install-php-extensions \
    xdebug \
    zip

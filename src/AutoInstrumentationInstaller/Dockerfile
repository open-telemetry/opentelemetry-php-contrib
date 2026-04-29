FROM composer:2@sha256:dc292c5c0f95f526b051d4c341bf08e7e2b18504c74625e3203d7f123050e318 as composer
FROM php:8.5@sha256:b070c68324c164aa1a656e25fde6474be4d0d1677fbb83e909e7eb2ed5e28a93
WORKDIR /srv/app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
  && install-php-extensions \
    xdebug \
    zip

FROM composer:2@sha256:f0809732b2188154b3faa8e44ab900595acb0b09cd0aa6c34e798efe4ebc9021 as composer
FROM php:8.2@sha256:b96eaaf7f53ccf643244b8bbcc45da061112c93d2bfb2e07603226c49b1f1705
WORKDIR /srv/app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
  && install-php-extensions \
    xdebug \
    zip

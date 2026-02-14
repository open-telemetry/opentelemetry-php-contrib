FROM composer:2@sha256:52e157261f5e691b13c0a49af08a8c4485c4053d8077e73f70cad3389b54dc02 as composer
FROM php:8.2@sha256:b96eaaf7f53ccf643244b8bbcc45da061112c93d2bfb2e07603226c49b1f1705
WORKDIR /srv/app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
  && install-php-extensions \
    xdebug \
    zip

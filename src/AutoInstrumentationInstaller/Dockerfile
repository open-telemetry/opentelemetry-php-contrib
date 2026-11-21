FROM composer:2.10.2@sha256:d020706319701a44468968321dccd0fce6620190159a7a9ec195d78e6e971c71 as composer
FROM php:8.5.9@sha256:0e17ef0527f296b85bfe4cfb5219b29cafc37224857ed73d28628ea142930ac8
WORKDIR /srv/app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
  && install-php-extensions \
    xdebug \
    zip

FROM composer:2@sha256:7725eb4545c438629ae8bde3ef0bb9a5038ef566126ad878442a69007242d267 as composer
FROM php:8.5@sha256:21d4bb8d913f8753a310726d89a46649d204c264fe6865f5d7292c374a277ec6
WORKDIR /srv/app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
  && install-php-extensions \
    xdebug \
    zip

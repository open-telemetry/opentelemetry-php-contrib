FROM composer:2.2.28@sha256:b73eb4a569ba455db55b453885120fb88a21b1e5d67d1b2f50a960f2a8230bea as composer
FROM php:8.5.7@sha256:1a5fd40baaeb88045a5cdfcec6b26bafcf40e3c2867f525b91cb746eb26d8fd9
WORKDIR /srv/app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
  && install-php-extensions \
    xdebug \
    zip

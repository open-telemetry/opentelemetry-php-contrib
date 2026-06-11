FROM composer:2@sha256:c883af18892268b3b8369c4a39c08f80b393383e79d80b75140a3ea489dbbb78 as composer
FROM php:8.5@sha256:5cfde668d96015de9af7aebfd6595c0930ebc6884e9ca3cd463df69cdb7e6286
WORKDIR /srv/app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
  && install-php-extensions \
    xdebug \
    zip

FROM composer:2.5 as build
COPY composer.json composer.lock ./
RUN composer install --ignore-platform-reqs

FROM wordpress:6.2
RUN pecl install opentelemetry-beta
COPY otel.php.ini $PHP_INI_DIR/conf.d/.
COPY --from=build /app/vendor /var/www/otel

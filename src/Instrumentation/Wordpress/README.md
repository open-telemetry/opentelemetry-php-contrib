# OpenTelemetry Wordpress auto-instrumentation

**Warning**: this is experimental, use at your own risk

## Requirements

* OpenTelemetry extension
* OpenTelemetry SDK exporter (required to actually export traces)
* Wordpress installation
* (optional) OpenTelemetry [SDK Autoloading](https://github.com/open-telemetry/opentelemetry-php/blob/main/examples/autoload_sdk.php) configured

## Overview
OpenTelemetry depends on composer, unlike Wordpress. I developed this against [johnpbloch/wordpress-core](https://github.com/johnpbloch/wordpress-core-installer), but it may also work with other installation methods.

### apache

Configure (eg via `.htaccess`) a PHP prepend and append file:

```
php_value auto_prepend_file /var/www/vendor/autoload.php
php_value auto_append_file /var/www/vendor/open-telemetry/opentelemetry-auto-wordpress/_post.php
```

This will install the composer autoloader before running Wordpress. As part of composer autoloading,
scripts are executed for installed modules (which includes this library's `_register.php` file).

`_post.php` adds some attributes and ends the root span after Wordpress has finished processing the request.

## Installation via composer

```bash
$ composer require open-telemetry/opentelemetry-auto-wordpress
```

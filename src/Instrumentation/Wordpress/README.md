# OpenTelemetry Wordpress auto-instrumentation

**Warning**: this is experimental, use at your own risk

## Requirements

* OpenTelemetry extension
* OpenTelemetry SDK exporter (required to actually export traces)
* Wordpress installation
* OpenTelemetry [SDK Autoloading](https://github.com/open-telemetry/opentelemetry-php/blob/main/examples/autoload_sdk.php) configured

## Overview
OpenTelemetry depends on composer, unlike Wordpress. I developed this against [johnpbloch/wordpress-core](https://github.com/johnpbloch/wordpress-core-installer), but it may also work with other installation methods.

### apache

Configure (eg via `.htaccess`) a PHP prepend file to initialize composer:

```
php_value auto_prepend_file /var/www/vendor/autoload.php
```

This will install the composer autoloader before running Wordpress. As part of composer autoloading,
scripts are executed for installed modules, importantly:
* OpenTelemetry SDK Autoloader
* this library's `_register.php` file

The initialization script for Wordpress auto-instrumentation, `_register.php`, will:
* start a root span based on the incoming request
* create a shutdown function to end the root span
* initialize auto-instrumentation hooks

## Installation via composer

```bash
$ composer require open-telemetry/opentelemetry-auto-wordpress
```

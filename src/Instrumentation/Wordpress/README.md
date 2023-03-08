# OpenTelemetry Wordpress auto-instrumentation

**Warning**: this is experimental, use at your own risk

**Preferred and simplest way to install auto-instrumentation (c extension plus instrumentation libraries) is to use [opentelemetry-instrumentation-installer](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/AutoInstrumentationInstaller).**
**The same process can be done manually by installing [c extension](https://github.com/open-telemetry/opentelemetry-php-instrumentation#installation) plus all needed instrumentation libraries like [Wordpress](#Installation-via-composer)**

## Requirements

* OpenTelemetry extension
* OpenTelemetry SDK exporter (required to actually export traces)
* Wordpress installation
* OpenTelemetry [SDK Autoloading](https://github.com/open-telemetry/opentelemetry-php/blob/main/examples/autoload_sdk.php) configured

## Overview
OpenTelemetry depends on composer, unlike Wordpress. I developed this against [johnpbloch/wordpress-core](https://github.com/johnpbloch/wordpress-core-installer), but it should also work with other installation methods.

### apache

Configure (eg via `.htaccess`) a PHP prepend file to initialize composer:

```
php_value auto_prepend_file /var/www/vendor/autoload.php
```

This will install the composer autoloader before running Wordpress. As part of composer autoloading,
scripts are executed for installed modules, importantly:
* OpenTelemetry SDK Autoloader
* this library's `_register.php` file

## Installation via composer

```bash
$ composer require open-telemetry/opentelemetry-auto-wordpress
```

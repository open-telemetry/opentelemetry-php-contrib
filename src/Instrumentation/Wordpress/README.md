[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-wordpress/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Wordpress)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-wordpress)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-wordpress/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-wordpress/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-wordpress/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-wordpress/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Wordpress auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Requirements

* OpenTelemetry extension
* OpenTelemetry SDK + exporter (required to actually export traces)
* WordPress installation
* OpenTelemetry [SDK Autoloading](https://github.com/open-telemetry/opentelemetry-php/blob/main/examples/autoload_sdk.php) configured

## Overview
OpenTelemetry depends on composer, unlike Wordpress. This extension was developed against
[johnpbloch/wordpress-core](https://github.com/johnpbloch/wordpress-core-installer),
but it should also work with other installation methods.

An example in Docker of extending the official Wordpress image to enable
auto-instrumentation: https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/examples/instrumentation/Wordpress

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

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=wordpress
```
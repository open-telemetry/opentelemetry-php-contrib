# OpenTelemetry CodeIgniter auto-instrumentation

**Warning**: this is experimental, use at your own risk

**Preferred and simplest way to install auto-instrumentation (c extension plus instrumentation libraries) is to use [opentelemetry-instrumentation-installer](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/AutoInstrumentationInstaller).**
**The same process can be done manually by installing [c extension](https://github.com/open-telemetry/opentelemetry-php-instrumentation#installation) plus all needed instrumentation libraries like [CodeIgniter](#Installation-via-composer)**

## Requirements

* [OpenTelemetry extension](https://opentelemetry.io/docs/instrumentation/php/automatic/#installation)
* OpenTelemetry SDK exporter (required to actually export traces)
* CodeIgniter 4.0+ installation

## Overview

Currently only root span creation is supported (`CodeIgniter\CodeIgniter::handleRequest` hook).

To export spans, you will need to create and register a `TracerProvider` early in your application's
lifecycle. This can be done either manually or using SDK autoloading.

### Using SDK autoloading

See https://github.com/open-telemetry/opentelemetry-php#sdk-autoloading

### Manual setup

```php
<?php
require_once 'vendor/autoload.php';

$tracerProvider = /*create tracer provider*/;
$scope = \OpenTelemetry\API\Instrumentation\Configurator::create()
    ->withTracerProvider($tracerProvider)
    ->activate();

//your application runs here

$scope->detach();
$tracerProvider->shutdown();
```

## Installation via composer

```bash
$ composer require open-telemetry/opentelemetry-auto-codeigniter
```

## Installing dependencies and executing tests

From CodeIgniter subdirectory:

```bash
$ composer install
$ ./vendor/bin/phpunit tests
```

## Configuration

Parts of this auto-instrumentation library can be configured, more options are available throught the
[General SDK Configuration](https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/sdk-environment-variables.md#general-sdk-configuration):

| Name                                | Default value | Values                  | Example     | Description                                                                     |
|-------------------------------------|---------------|-------------------------|-------------|---------------------------------------------------------------------------------|
| OTEL_PHP_DISABLED_INSTRUMENTATIONS  | []            | Instrumentation name(s) | codeigniter | Disable one or more installed auto-instrumentations, names are comma seperated. |

Configurations can be provided as environment variables, or via `php.ini` (or a file included by `php.ini`)

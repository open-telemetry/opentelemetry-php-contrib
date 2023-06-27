# OpenTelemetry PSR-3 auto-instrumentation

**Preferred and simplest way to install auto-instrumentation (c extension plus instrumentation libraries) is to use [opentelemetry-instrumentation-installer](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/AutoInstrumentationInstaller).**
**The same process can be done manually by installing [c extension](https://github.com/open-telemetry/opentelemetry-php-instrumentation#installation) plus all needed instrumentation libraries like [PSR-3](#installation-via-composer)**

## Requirements

- OpenTelemetry extension
- OpenTelemetry SDK an exporter (required to actually export traces)
- A psr-3 logger
- (optional) OpenTelemetry [SDK Autoloading](https://github.com/open-telemetry/opentelemetry-php/blob/main/examples/autoload_sdk.php) configured

## Overview

Auto-instrumentation hooks are registered via composer, and automatically inject trace id and span id into log message context of any psr3 logger.

### Using SDK autoloading

See https://github.com/open-telemetry/opentelemetry-php#sdk-autoloading

## Manual configuration

If you are not using SDK autoloading, you will need to create and register a `TracerProvider` early in your application's lifecycle:

```php
<?php
require_once 'vendor/autoload.php';

$tracerProvider = /*create tracer provider*/;
$scope = \OpenTelemetry\API\Instrumentation\Configurator::create()
    ->withTracerProvider($tracerProvider)
    ->activate();

// Create root span
$root = $tracerProvider->getTracer('psr3-demo')->spanBuilder('root')->startSpan();
$rootScope = $root->activate();

$log = new \Monolog\Logger('OpenTelemetry'); // Install with `composer require monolog/monolog`
$log->pushHandler(new \Monolog\Handler\ErrorLogHandler());

// add records to the log
$log->info('Hi OpenTelemetry.');
// Output: [{0000-00-00T00:00:00.000000+00:00}] OpenTelemetry.INFO: Hi OpenTelemetry. {"traceId":"0d60f3595515bade972d58f40ed1d3ca","spanId":"7e267228e3de7d98"} []

$rootScope->detach();
$root->end();
$scope->detach();
$tracerProvider->shutdown();
```

## Installation via composer

```bash
composer require open-telemetry/opentelemetry-auto-psr3
```

## Configuration

Parts of this auto-instrumentation library can be configured, more options are available throught the
[General SDK Configuration](https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/sdk-environment-variables.md#general-sdk-configuration):

| Name                               | Default value | Values                  | Example | Description                                                                     |
| ---------------------------------- | ------------- | ----------------------- | ------- | ------------------------------------------------------------------------------- |
| OTEL_PHP_DISABLED_INSTRUMENTATIONS | []            | Instrumentation name(s) | psr3    | Disable one or more installed auto-instrumentations, names are comma seperated. |

Configurations can be provided as environment variables, or via `php.ini` (or a file included by `php.ini`)

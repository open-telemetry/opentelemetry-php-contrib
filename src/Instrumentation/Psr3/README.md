# OpenTelemetry PSR-3 auto-instrumentation

## Requirements

- [OpenTelemetry extension](https://opentelemetry.io/docs/instrumentation/php/automatic/#installation)
- OpenTelemetry SDK and exporter (required to actually export signal data)
- A psr-3 logger
- OpenTelemetry [SDK Autoloading](https://github.com/open-telemetry/opentelemetry-php/blob/main/examples/autoload_sdk.php) configured

## Overview

Auto-instrumentation hooks are registered via composer, and depending on the mode, will:

* automatically inject trace id and span id into log message context of any psr3 logger; or
* transform the message into the OpenTelemetry LogRecord format, for export to an OpenTelemetry logging-compatible backend

### Using SDK autoloading

See https://github.com/open-telemetry/opentelemetry-php#sdk-autoloading

## Mode

The package can operate in two modes, controlled by the environment variable `OTEL_PHP_PSR3_MODE`:

### `inject`
Inject `traceId` and `spanId` of the active trace span into the context of each logged message. Depending on the PSR-3 implementation,
the values may be written to the log output, or may be available for interpolation into the log message.

For example:

```php
putenv('OTEL_PHP_PSR3_MODE=inject');
require 'vendor/autoload.php';

$logger = /* create logger */
$logger->info('traceId={traceId} spanId={spanId}');
```

### `export`
The logged output will be processed and emitted by the logger as normal, but the output will also be encoded using
the [OpenTelemetry log model](https://opentelemetry.io/docs/specs/otel/logs/data-model/) and can be
exported to an OpenTelemetry-compatible backend.

```php
putenv('OTEL_PHP_PSR3_MODE=export');
putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
putenv('OTEL_LOGS_EXPORTER=console');
require 'vendor/autoload.php';

$logger = /* create logger */
$logger->info('Hello, OTEL');
```

## Installation via composer

```bash
composer require open-telemetry/opentelemetry-auto-psr3
```

## Configuration

Parts of this auto-instrumentation library can be configured, more options are available through the
[General SDK Configuration](https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/sdk-environment-variables.md#general-sdk-configuration):

| Name                               | Default value | Values                  | Example | Description                                                                     |
| ---------------------------------- |---------------| ----------------------- |---------|---------------------------------------------------------------------------------|
| OTEL_PHP_DISABLED_INSTRUMENTATIONS | []            | Instrumentation name(s) | psr3    | Disable one or more installed auto-instrumentations, names are comma seperated. |
| OTEL_PHP_PSR3_MODE                 | inject        | inject, export          | export  | Change the behaviour of the package                                             |

Configurations can be provided as environment variables, or via `php.ini` (or a file included by `php.ini`)

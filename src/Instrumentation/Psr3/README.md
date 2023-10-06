[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-psr3/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Psr3)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-psr3)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-psr3/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-psr3/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-psr3/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-psr3/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry PSR-3 auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via composer, and depending on the mode, will:

* automatically inject trace id and span id into log message context of any psr3 logger; or
* transform the message into the OpenTelemetry LogRecord format, for export to an OpenTelemetry logging-compatible backend

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

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=psr3
```

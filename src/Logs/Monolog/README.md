[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-logs-monolog/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Logs/Monolog)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-logger-monolog)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-logger-monolog/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-logger-monolog/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-logger-monolog/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-logger-monolog/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Monolog handler

A monolog handler for OpenTelemetry. See https://opentelemetry.io/docs/instrumentation/php/manual/#logs for further documentation.

## Requirements

### API + SDK

This package depends on the OpenTelemetry API, but a configured [OpenTelemetry SDK](https://opentelemetry.io/docs/instrumentation/php/sdk/) should also be provided.

### Exporter

Usually logs are exported to a `receiver` via the `otlp` protocol in the `protobuf` format, via http or `gRPC`.

This requires:

- a `protobuf` implementation; either the protobuf extension or the `google/protobuf` package
- the `open-telemetry/exporter-otlp` package
- the `open-telemetry/transport-grpc` package, if using gRPC transport
- a PSR-7 and PSR-18 implementation, if using HTTP transport

### Receiver
Logs must be emitted to a receiver/system that understands the OpenTelemetry protocol, such as the [OpenTelemetry collector](https://opentelemetry.io/docs/collector/).

## Installation

```shell
composer require open-telemetry/opentelemetry-logger-monolog
```

## Usage

The OpenTelemetry handler, configured with an OpenTelemetry `LoggerProvider`, is used to send Monolog `LogRecord`s to OpenTelemetry.

The `LoggerProvider` can be configured in a number of ways: manually, via an SDK Builder, or automatically (using environment/php.ini variables).

### Manual configuration

Set up an SDK LoggerProvider and pass it to the handler:

```php
$loggerProvider = new \OpenTelemetry\SDK\Logs\LoggerProvider(/* params */);
$handler = new \OpenTelemetry\Contrib\Logs\Monolog\Handler(
    $loggerProvider,
    'info',
    true,
);
```

### Automatic configuration

If you use [OpenTelemetry SDK autoloading](https://opentelemetry.io/docs/instrumentation/php/sdk/#autoloading), you can retrieve the global logger
provider. That may be a no-op implementation if there was any misconfiguration.

See [autoload-sdk example](./example/autoload-sdk.php) for how to use autoloading with the OpenTelemetry SDK.

### Create a Logger

Finally, add the handler to a Monolog logger:

```php
$logger = new \Monolog\Logger(
    'name',
    [$handler],
);
$logger->info('hello world');
```

### Attributes Mode

This OpenTelemetry handler will convert any `context` array or `extra` array in the `Monolog\LogRecord` to `OpenTelemetry\API\Logs\LogRecord` attributes. There are two options for handling conflicts between the classes.

_Note 1: Exceptions have special handling in both the PSR-3 spec and the OpenTelemetry spec. If a PHP `Throwable` is included in the `context` array with a key of `exception`, it will be added as `exception.` attributes to the OpenTelemetry Log Record._

_Note 2: Both [Monolog](https://github.com/Seldaek/monolog/blob/main/src/Monolog/Formatter/NormalizerFormatter.php) and the [OpenTelemetry Protocol](https://github.com/open-telemetry/opentelemetry-php/blob/main/src/Contrib/Otlp/AttributesConverter.php) employ serialization algorithms when encoding attributes. This combination can lead to obtuse JSON blobs in the OTLP log records; this can be avoided by using only scalar values for attributes._

By default, the attribute keys will be `context` and `extra`, along with `context.` and `extra.` prefixed keys with the individual array entries. Example:

```php
$host = new stdClass();
$host->name = 'example.com';
$host->tcp = 80;
new Monolog\LogRecord(
    ...,
    context: [
        'foo' => 'bar',
        'baz' => 'bat',
    ],
    extra: [
        'host' => $host,
    ]
);

/**
 * becomes:
 *
 * OpenTelemetry\API\Logs\LogRecord (
 *     ...,
 *     attributes => array (
 *         context => array (
 *             foo => 'bar',
 *             baz => 'bat',
 *         )
 *         context.foo => 'bar'
 *         context.baz => 'bat'
 *         extra => array (
 *             host => array (
 *                 stdClass => array (
 *                     name => 'example.com'
 *                     tcp => 80
 *                 )
 *             )
 *         )
 *         extra.host => stdClass (
 *             name => 'example.com'
 *             tcp => 80
 *         )
 *     )
 * )
 */
```

Alternatively, if your `context` and `extra` keys do not conflict with OpenTelemetry Semantic Conventions for Attribute keys, you can set `OTEL_PHP_MONOLOG_ATTRIB_MODE=otel` and they will be sent directly as Attributes. Example:

```php
new Monolog\LogRecord(
    ...,
    context: [
        'myapp.data.foo' => 'bar',
        'myapp.data.baz' => 'bat',
    ],
    extra: [
        'server.address' => 'example.com',
        'server.port' => 80,
    ]
);

/**
 * becomes:
 *
 * OpenTelemetry\API\Logs\LogRecord (
 *     ...,
 *     attributes => array (
 *         myapp.data.foo => 'bar'
 *         myapp.data.baz => 'bat'
 *         server.address => 'example.com'
 *         server.port => 80
 *      )
 * )
 */
```

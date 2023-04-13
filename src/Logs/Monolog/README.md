# OpenTelemetry Monolog handler

A monolog handler for OpenTelemetry. See See https://opentelemetry.io/docs/instrumentation/php/logging for further documentation.

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
composer require open-telemetry/logger-monolog
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

See [./example/autoload-sdk.php](autoload-sdk example) for how to use autoloading with the OpenTelemetry SDK.

## Create a Logger

Finally, add the handler to a Monolog logger:

```php
$logger = new \Monolog\Logger(
    'name',
    [$handler],
);
$logger->info('hello world');
```

# OpenTracing shim for OpenTelemetry

This package is intended to help OpenTracing users migrate to OpenTelemetry. The OpenTelemetry documentation provides guidance
on how to migrate to OpenTelemetry: https://opentelemetry.io/docs/migration/opentracing/

# Installation

## API + SDK

This package depends on the OpenTelemetry API, but an  [OpenTelemetry SDK](https://opentelemetry.io/docs/instrumentation/php/sdk/)
and an exporter should also be provided to enable exporting spans.

You usually need to supply a PSR-7 and PSR-18 implementation, since most exporters use HTTP to transport telemetry data (gRPC is the exception).

## OTLP Exporter

Usually logs are exported to some receiver via the `otlp` protocol in the `protobuf` format, via http or `gRPC`.

This requires:

- a `protobuf` implementation; either the protobuf extension or the `google/protobuf` package
- the `open-telemetry/exporter-otlp` package
- the `open-telemetry/transport-grpc` package, if using gRPC transport
- a PSR-7 and PSR-18 implementation, if using HTTP transport

## Zipkin exporter

OpenTelemetry supports exporting via the zipkin protocol, which requires the `open-telemetry/exporter-zipkin` package. Traces can
be exported to a zipkin instance directly, or to any other service which supports the zipkin protocol.

# Setup

To use this package, you need to create an OpenTelemetry `tracer provider`, which is the only parameter used by the shim tracer:

```php
$tracerProvider = new OpenTelemetry\SDK\Trace\TracerProvider(/*params*/);
OpenTelemetry\SDK\Common\Util\ShutdownHandler::register([$tracerProvider, 'shutdown']);
$tracer = new OpenTelemetry\Contrib\Shim\OpenTracing\Tracer($tracerProvider);
```

There are a number of ways to set up a tracer provider, please see the [official documentation](https://opentelemetry.io/docs/instrumentation/php/sdk/)
or the [examples](./examples).

# Notes

- OpenTelemetry does not support setting span kind after span creation, so adding a `span.kind` tag will not set the span's kind. An attribute
will still be emitted, though.
- `Span::log([/*$fields*/])` will use an `event` field as the log name, otherwise `log`.
- errors may be logged via `Span::log`, using the key `exception`. A `Throwable` should be the field's value, but a string is allowable and will be converted to an exception

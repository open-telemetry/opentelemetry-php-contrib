This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry ReactPHP HTTP Browser auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview

Auto-instrumentation hooks are registered via composer, which will:

* create spans automatically for each React HTTP request that is sent
* add a `traceparent` header to the request to facilitate distributed tracing

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=react-http
```

Custom HTTP methods can replace the known methods via environment variables, e.g.:

```shell
OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS="GET,HEAD,POST,PUT,DELETE,CONNECT,OPTIONS,TRACE,PATCH,MyCustomMethod"
```

Request and/or response headers can be added as span attributes via environment variables, e.g.:

```shell
OTEL_INSTRUMENTATION_HTTP_REQUEST_HEADERS=Accept
OTEL_INSTRUMENTATION_HTTP_RESPONSE_HEADERS="Content-Length,Content-Type"
```

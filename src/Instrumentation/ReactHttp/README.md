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

Request and/or response headers can be added as span attributes by adding to the `php.ini`:

```ini
otel.instrumentation.http.request_headers[]="Accept"
otel.instrumentation.http.response_headers[]="Content-Type"
```

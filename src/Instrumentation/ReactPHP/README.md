[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-reactphp/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/ReactPHP)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-reactphp)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-reactphp/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-reactphp/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-reactphp/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-reactphp/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry ReactPHP HTTP Browser auto-instrumentation

This is an OpenTelemetry auto-instrumentation package for the [ReactPHP HTTP library](https://reactphp.org/http/). Currently only the Browser (client) component is instrumented.

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview

This library is provides the following:

- OpenTelemetry Semantic Conventions v1.32.0:
    - [HTTP Client Spans](https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span) - required and HTTP header (opt-in) attributes only
    - [HTTP Client Metrics](https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#http-client) - required attributes only
- W3C Trace Context:
    - [Trace Context HTTP Request Headers](https://www.w3.org/TR/trace-context/#trace-context-http-headers-format)

> [!NOTE]
> HTTP Client Span lifetime behavior differs based on how ReactPHP is utilized; see [examples/README.md](examples/README.md) for more information.

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=reactphp
```

Custom HTTP methods can replace the known methods via an environment variable, e.g.:

```shell
OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS="GET,HEAD,POST,PUT,DELETE,CONNECT,OPTIONS,TRACE,PATCH,MyCustomMethod"
```

Additional HTTP query string parameters can be redacted via an environment variable, e.g.,

```shell
OTEL_PHP_INSTRUMENTATION_URL_SANITIZE_FIELD_NAMES="password,passwd,pwd,secret"
```

Request and/or response headers can be added as span attributes via environment variables, e.g.:

```shell
OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS=Accept
OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS="Content-Length,Content-Type"
```

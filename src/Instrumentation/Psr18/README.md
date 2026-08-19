[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-psr18/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Psr18)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-psr18)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-psr18/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-psr18/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-psr18/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-psr18/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry PSR-18 auto-instrumentation
Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via composer, which will:

* create spans automatically for each PSR-18 request that is sent
* add a `traceparent` header to the request to facilitate distributed tracing

## Configuration

### Disabling PSR-18 instrumentation

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=psr18
```

### Request and response header capturing

PSR-18 auto-instrumentation supports capturing headers from both requests and responses as span attributes. This feature is disabled by default and can be enabled through environment variables or array directives in the `php.ini` configuration file. Header name matching is case-insensitive.

#### Environment variables configuration

```bash
OTEL_INSTRUMENTATION_HTTP_CLIENT_CAPTURE_REQUEST_HEADERS=host,accept
OTEL_INSTRUMENTATION_HTTP_CLIENT_CAPTURE_RESPONSE_HEADERS=content-type,server
```

The legacy options are still supported but are not recommended as they may be deprecated in a future release:

```bash
OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS=host,accept
OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS=content-type,server
```

#### php.ini configuration

```ini
otel.instrumentation.http.request_headers[]=host
otel.instrumentation.http.request_headers[]=accept

otel.instrumentation.http.response_headers[]=content-type
otel.instrumentation.http.response_headers[]=server
```

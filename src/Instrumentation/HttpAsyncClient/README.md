[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-http-async/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/HttpAsyncClient)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-http-async)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-http-async/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-http-async/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-http-async/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-http-async/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry HTTPlug async auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via composer, which will:

* create spans automatically for each async HTTP request that is sent
* add a `traceparent` header to the request to facilitate distributed tracing

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=http-async-client
```

Request headers can be added as span attributes, if the header's name is found in the `php.ini` variable: `otel.instrumentation.http.request_headers`

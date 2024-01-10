[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-guzzle/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Guzzle)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-guzzle)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-guzzle/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-guzzle/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-guzzle/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-guzzle/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Guzzle auto-instrumentation
Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via composer.

* create spans automatically for each Guzzle request that is sent (sync or async)
* add a `traceparent` header to the request to facilitate distributed tracing

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=guzzle
```

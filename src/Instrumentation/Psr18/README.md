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

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=psr18
```

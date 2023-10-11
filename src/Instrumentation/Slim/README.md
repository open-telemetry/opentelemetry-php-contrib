[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-slim/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Slim)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-slim)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-slim/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-slim/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-slim/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-slim/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Slim Framework auto-instrumentation
Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via composer, and spans will automatically be created for:
- `App::handle()` - root span
- `InvocationStrategyInterface` - controller/action
- `RoutingMiddleware::performRouting` - update root span's name with either route name or pattern

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=slim
```

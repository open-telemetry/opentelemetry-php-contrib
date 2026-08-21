[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-laravel/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Laravel)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-laravel)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-laravel/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-laravel/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-laravel/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-laravel/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Laravel auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via composer, and spans will automatically be created.

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=laravel
```

### Outbound HTTP client trace propagation

By default, outbound requests made via Laravel's HTTP client (`Illuminate\Http\Client`)
do not have trace context (`traceparent`/`tracestate`) injected into their headers.
This is opt-in for security reasons: the instrumentation cannot distinguish between
requests to trusted internal services and external third-party endpoints. Injecting
trace context and baggage into the latter could leak sensitive information.

To enable trace context propagation on outbound HTTP client requests:

```shell
OTEL_PHP_INSTRUMENTATION_LARAVEL_HTTP_CLIENT_PROPAGATION_ENABLED=true
```

**Only enable this if all outbound HTTP client requests are sent to trusted internal services.**

Defaults to `false`.

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

### PHP-FPM: Non-blocking telemetry export

By default, the OTel SDK exports spans during the PHP process shutdown phase using a synchronous,
blocking HTTP POST. Under PHP-FPM, when the collector is slow or unreachable, the FPM worker is
held for the full exporter timeout, causing worker pool exhaustion and upstream proxy timeouts.

Enable this opt-in feature to call `fastcgi_finish_request()` during `Kernel::terminate()`. This
closes the FastCGI connection to nginx before the blocking export begins. The client and upstream
proxy see the request as complete immediately; the FPM worker finishes the export in the
background using the existing SDK shutdown handler.

```shell
OTEL_PHP_INSTRUMENTATION_LARAVEL_FPM_FINISH_REQUEST_ENABLED=true
```

**Configure exporter timeouts first (required):**

Bound the background worker time even during collector outages:

```shell
OTEL_EXPORTER_OTLP_TIMEOUT=500
OTEL_EXPORTER_OTLP_TRACES_TIMEOUT=500
OTEL_BSP_EXPORT_TIMEOUT=1000
```

**Tradeoffs:**

- Eliminates user-facing latency spikes and upstream proxy timeouts caused by blocking OTLP export.
- FPM worker pool can still be exhausted during prolonged outages; bound this with the timeouts above.
- `fastcgi_finish_request()` is a PHP-FPM built-in. Outside FPM (CLI, tests), it does not exist
  and is silently skipped.
- **Do not enable in long-running Laravel workers (Octane/Swoole/RoadRunner).** The feature detects
  Octane via the `LARAVEL_OCTANE` environment variable and the application container, and logs a
  warning if misused rather than silently misbehaving.

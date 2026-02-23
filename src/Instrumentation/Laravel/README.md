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

### Selective Instrumentation

You can selectively enable or disable specific instrumentations using the following environment variables:

| Environment Variable | Description |
|---------------------|-------------|
| `OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS` | Comma-separated list of instrumentations to enable (only these will be active) |
| `OTEL_LARAVEL_DISABLED_INSTRUMENTATIONS` | Comma-separated list of instrumentations to disable |

#### Available Instrumentation Names

| Name | Description |
|------|-------------|
| `http` | HTTP request/response handling |
| `console` | Artisan CLI commands |
| `queue` | Queue jobs (push, process, worker) |
| `eloquent` | Eloquent ORM operations |
| `serve` | Local development server (`php artisan serve`) |
| `cache` | Cache events (hit, miss, write, forget) |
| `db` | Database queries |
| `http-client` | HTTP client requests (external API calls) |
| `exception` | Exception recording |
| `log` | Log messages |
| `redis` | Redis commands |

#### Group Aliases

| Alias | Expands To |
|-------|------------|
| `all` | All instrumentations |
| `watchers` | `cache`, `db`, `http-client`, `exception`, `log`, `redis` |

#### Priority

- If neither variable is set: all instrumentations are enabled (default behavior)
- If only `OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS` is set: only specified instrumentations are enabled
- If only `OTEL_LARAVEL_DISABLED_INSTRUMENTATIONS` is set: specified instrumentations are disabled
- If both are set: `OTEL_LARAVEL_DISABLED_INSTRUMENTATIONS` takes priority (disabled items are removed from enabled list)

#### Examples

```shell
# Enable only HTTP and Queue instrumentation
OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS=http,queue

# Disable only Redis and Log (all others remain enabled)
OTEL_LARAVEL_DISABLED_INSTRUMENTATIONS=redis,log

# Enable only watchers (cache, db, http-client, exception, log, redis)
OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS=watchers
```

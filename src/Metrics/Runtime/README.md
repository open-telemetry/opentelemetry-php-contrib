[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/open-telemetry/opentelemetry-php-contrib/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Metrics/Runtime)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-metrics-runtime/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-metrics-runtime/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-metrics-runtime/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-metrics-runtime/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry PHP Runtime Metrics

Collects PHP runtime metrics — memory, garbage collection, OPcache, and CPU usage — and exposes them as OpenTelemetry observable instruments.

## Requirements

* PHP 8.1+
* OpenTelemetry API

## Installation

```bash
composer require open-telemetry/opentelemetry-metrics-runtime
```

## Usage

### Automatic (recommended)

The package registers itself via [SPI](https://github.com/Nevay/spi) as an `Instrumentation`. When the OpenTelemetry SDK calls `SdkAutoloader::autoload()`, metrics are registered automatically with the configured `MeterProvider`. No additional code is required.

```shell
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_METRICS_EXPORTER=otlp
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
```

### Manual

If you need to control when metrics are registered:

```php
use OpenTelemetry\Contrib\Metrics\Runtime\RuntimeMetrics;

RuntimeMetrics::register($meterProvider);
```

## Metrics

### Memory (`memory`)

| Metric | Type | Unit | Description |
|--------|------|------|-------------|
| `process.runtime.php.memory.usage` | UpDownCounter | `By` | Current memory usage. Reported for both `real` (OS allocation) and `emalloc` (PHP internal) via the `memory.type` attribute. |
| `process.runtime.php.memory.peak_usage` | UpDownCounter | `By` | Peak memory usage since script start. Same `memory.type` attribute breakdown. |
| `process.runtime.php.memory.limit` | Gauge | `By` | Memory limit from `php.ini`. `-1` means unlimited. |

### Garbage Collection (`gc`)

| Metric | Type | Unit | Description |
|--------|------|------|-------------|
| `process.runtime.php.gc.runs` | Counter | `{run}` | Total number of GC cycles run. |
| `process.runtime.php.gc.collected` | Counter | `{object}` | Total number of objects collected. |
| `process.runtime.php.gc.roots` | Gauge | `{object}` | Current number of objects in the root buffer. |
| `process.runtime.php.gc.threshold` | Gauge | `{object}` | Number of roots required to trigger a GC cycle. |
| `process.runtime.php.gc.collector_time` | Counter | `s` | Cumulative time spent in the GC collector. **PHP 8.3+** |
| `process.runtime.php.gc.destructor_time` | Counter | `s` | Cumulative time spent running destructors during GC. **PHP 8.3+** |
| `process.runtime.php.gc.free_time` | Counter | `s` | Cumulative time spent freeing memory during GC. **PHP 8.3+** |

### OPcache (`opcache`)

Registered only when OPcache is enabled (`opcache.enable=1`). In CLI context, also requires `opcache.enable_cli=1`.

| Metric | Type | Unit | Description |
|--------|------|------|-------------|
| `process.runtime.php.opcache.memory_used` | UpDownCounter | `By` | Memory used by cached scripts. |
| `process.runtime.php.opcache.memory_free` | UpDownCounter | `By` | Free memory in the OPcache buffer. |
| `process.runtime.php.opcache.memory_wasted` | UpDownCounter | `By` | Wasted (fragmented) memory — requires restart to reclaim. |
| `process.runtime.php.opcache.hits` | Counter | `{hit}` | Total cache hits. |
| `process.runtime.php.opcache.misses` | Counter | `{miss}` | Total cache misses. |
| `process.runtime.php.opcache.hit_rate` | Gauge | `%` | Cache hit rate percentage. |
| `process.runtime.php.opcache.cached_scripts` | Gauge | `{script}` | Number of scripts currently in cache. |
| `process.runtime.php.opcache.interned_strings.memory_used` | UpDownCounter | `By` | Memory used by interned strings. |
| `process.runtime.php.opcache.interned_strings.memory_free` | UpDownCounter | `By` | Free memory in the interned strings buffer. |
| `process.runtime.php.opcache.interned_strings.count` | Gauge | `{string}` | Number of interned strings currently stored. |

### CPU (`cpu`)

Registered only on platforms where `getrusage()` is available (Linux, macOS, Windows).

| Metric | Type | Unit | Description |
|--------|------|------|-------------|
| `process.cpu.time` | Counter | `s` | CPU time consumed. Reported for `user` and `system` modes via the `cpu.mode` attribute. |
| `process.runtime.php.cpu.voluntary_context_switches` | Counter | `{switch}` | Number of times the process voluntarily yielded the CPU. |
| `process.runtime.php.cpu.involuntary_context_switches` | Counter | `{switch}` | Number of times the process was preempted involuntarily. |

## Configuration

### Disable the entire package

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=metrics-runtime
```

### Disable individual metric groups

**Option 1: via `OTEL_PHP_DISABLED_METRICS`** — lightweight, no config file needed:

```shell
# Disable OPcache and CPU metrics only
OTEL_PHP_DISABLED_METRICS=opcache,cpu

# Disable GC metrics only
OTEL_PHP_DISABLED_METRICS=gc
```

Available group names: `memory`, `gc`, `opcache`, `cpu`. Values are case-insensitive.

**Option 2: via `OTEL_CONFIG_FILE`** — standard OTel SDK configuration, supports fine-grained control (e.g. filtering by attributes). Each group uses its own meter named `io.opentelemetry.contrib.php.runtime.{group}`, which can be targeted with meter configurators or views:

```yaml
# otel-config.yaml
meter_provider:
  views:
    - selector:
        meter_name: io.opentelemetry.contrib.php.runtime.opcache
      stream:
        aggregation:
          drop:
```

```shell
OTEL_CONFIG_FILE=/path/to/otel-config.yaml
```

## Maintainer

[@intuibase](https://github.com/intuibase)

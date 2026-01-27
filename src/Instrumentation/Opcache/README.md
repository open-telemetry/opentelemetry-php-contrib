[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-opcache/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/opcache)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-opcache)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-opcache/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-opcache/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-opcache/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-opcache/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry PHP OPcache Instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview

This instrumentation package captures PHP OPcache metrics and adds them as attributes to the active span.
It automatically registers a shutdown function to collect OPcache metrics at the end of the request.

The following OPcache metrics are captured:

### Basic Status
- `opcache.enabled` - Whether OPcache is enabled
- `opcache.available` - Whether OPcache is available

### Memory Usage
- `opcache.memory.used_bytes` - Memory used by OPcache in bytes
- `opcache.memory.free_bytes` - Free memory available to OPcache in bytes
- `opcache.memory.wasted_bytes` - Wasted memory in bytes
- `opcache.memory.used_percentage` - Percentage of total memory used
- `opcache.memory.wasted_percentage` - Percentage of total memory wasted

### Cache Statistics
- `opcache.scripts.cached` - Number of cached scripts
- `opcache.hits.total` - Total number of cache hits
- `opcache.misses.total` - Total number of cache misses
- `opcache.hit_rate.percentage` - Cache hit rate percentage
- `opcache.keys.cached` - Number of cached keys
- `opcache.keys.max_cached` - Maximum number of cached keys

### Restart Statistics
- `opcache.restarts.oom` - Number of out-of-memory restarts
- `opcache.restarts.hash` - Number of hash restarts
- `opcache.restarts.manual` - Number of manual restarts

### Interned Strings
- `opcache.interned_strings.buffer_size` - Interned strings buffer size
- `opcache.interned_strings.used_memory` - Memory used by interned strings
- `opcache.interned_strings.free_memory` - Free memory for interned strings
- `opcache.interned_strings.strings_count` - Number of interned strings
- `opcache.interned_strings.usage_percentage` - Percentage of interned strings buffer used

## Usage

The instrumentation is automatically registered via composer. No additional configuration is required.

You can also manually add OPcache metrics to the current active span:

```php
use OpenTelemetry\Contrib\Instrumentation\opcache\opcacheInstrumentation;

// Add OPcache metrics to the current active span
opcacheInstrumentation::addOpcacheMetricsToRootSpan();
```

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=opcache
```

## Requirements

- PHP 8.0 or higher
- OPcache extension
- OpenTelemetry extension

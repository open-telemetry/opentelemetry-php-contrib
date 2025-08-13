[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/open-telemetry/opentelemetry-php-contrib/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/PostgreSql)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/open-telemetry/opentelemetry-php-contrib)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-postgresql/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-postgresql/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-postgresql/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-postgresql/)

> This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry PostgreSQL auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview

This package provides auto-instrumentation for the PostgreSQL native PHP extension (`ext-pgsql`).
Hooks are registered via Composer, and client spans are automatically created for key database operations.

Supported functions include:

### Connection
- `pg_connect`
- `pg_pconnect`

### Queries
- `pg_query`
- `pg_query_params`
- `pg_send_query`
- `pg_send_query_params`
- `pg_get_result`

### Prepared Statements
- `pg_prepare`
- `pg_send_prepare`
- `pg_execute`
- `pg_send_execute`

### Table/Row Operations
- `pg_insert`
- `pg_select`
- `pg_update`
- `pg_delete`

### COPY
- `pg_copy_from`
- `pg_copy_to`

### Large Objects (LOB)
- `pg_lo_create`
- `pg_lo_open`
- `pg_lo_write`
- `pg_lo_read`
- `pg_lo_read_all`
- `pg_lo_unlink`
- `pg_lo_import`
- `pg_lo_export`


## Configuration

### Disabling PostgreSQL instrumentation

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=postgresql
```

## Compatibility

PHP 8.2 or newer is required

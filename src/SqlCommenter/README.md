[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-sqlcommenter/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/SqlCommenter)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-sqlcommenter)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-sqlcommenter/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-sqlcommenter/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-sqlcommenter/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-sqlcommenter/)

> **Note:** This is a read-only subtree split of [open-telemetry/opentelemetry-php-contrib](https://github.com/open-telemetry/opentelemetry-php-contrib).

# OpenTelemetry SQL Commenter

OpenTelemetry SQL Commenter for PHP provides a [SqlCommenter](https://opentelemetry.io/docs/specs/semconv/database/database-spans/#sql-commenter) implementation, enabling you to inject trace and context comments into SQL queries for enhanced observability and distributed tracing.

## Installation

Install via Composer:

```bash
composer require open-telemetry/opentelemetry-sqlcommenter
```

## Usage

Inject comments into your SQL query as follows:

```php
use OpenTelemetry\SqlCommenter\SqlCommenter;

$comments = [
    'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00',
    'custom' => 'value',
];
$query = SqlCommenter::inject($query, $comments);
```

## Configuration

- **Context Propagators**

  Set the propagators to use (comma-separated):

  ```shell
  OTEL_PHP_SQLCOMMENTER_CONTEXT_PROPAGATORS=tracecontext
  ```
  Default: `''`

- **SQL Commenter Attribute**

  Add SQL comments to `DbAttributes::DB_QUERY_TEXT` in span attributes:

  ```shell
  otel.sqlcommenter.attribute = true
  ```
  or via environment variable:
  ```shell
  OTEL_PHP_SQLCOMMENTER_ATTRIBUTE=true
  ```
  Default: `false`

- **Prepend Comments**

  Prepend comments to the query statement using either a configuration directive:

  ```shell
  otel.sqlcommenter.prepend = true
  ```
  or via environment variable:

  ```shell
  OTEL_PHP_SQLCOMMENTER_PREPEND=true
  ```
  Default: `false`

## Development

Install dependencies and run tests from the `SqlCommenter` subdirectory:

```bash
composer install
./vendor/bin/phpunit tests
```


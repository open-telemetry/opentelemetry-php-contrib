[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-sqlcommenter/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/SqlCommenter)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-sqlcommenter)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-sqlcommenter/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-sqlcommenter/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-sqlcommenter/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-sqlcommenter/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry SQL Commenter

This package provides a [SqlCommenter](https://opentelemetry.io/docs/specs/semconv/database/database-spans/#sql-commenter) to inject comments into SQL queries.

## Usage

You can add comments to your sql query as follows:

```php
$comments = [];
$query = SqlCommenter::inject($query, $comments);
```

## Installation via composer

```bash
$ composer require open-telemetry/opentelemetry-sqlcommenter
```

## Configuration

Configuration directive
```shell
otel.instrumentation.sql_commenter.prepend = true
```
or environment variable
```shell
OTEL_PHP_INSTRUMENTATION_SQL_COMMENTER_PREPEND=true
```
specifies to prepend comments to the query statement. Default value: `false`

## Installing dependencies and executing tests

From SqlCommenter subdirectory:

```bash
$ composer install
$ ./vendor/bin/phpunit tests
```

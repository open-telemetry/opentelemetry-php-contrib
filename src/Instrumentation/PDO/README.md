[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-pdo/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/PDO)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-pdo)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-pdo/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-pdo/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-pdo/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-pdo/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry PDO (PHP DataObjects) auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via composer, and spans will automatically be created for
selected `PDO` and `PDOStatement` methods.

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=pdo
```
                     
In case UI used to view telemetry data does not support links between spans (for example newrelic),
you can optionally enable setting db statements attribute to `fetchAll` and `execute` spans using 
configuration directive:
```
otel.instrumentation.pdo.distribute_statement_to_linked_spans = true
```
or environment variable:
```shell
OTEL_PHP_INSTRUMENTATION_PDO_DISTRIBUTE_STATEMENT_TO_LINKED_SPANS=true
```

## Database Context Propagation

Enable context propagation for database queries (pdo_mysql, pdo_pgsql only) by installing the following packages:
```shell
composer require open-telemetry/opentelemetry-sqlcommenter
```
Currently, only the following functions support context propagation:
* `PDO::query`
* `PDO::exec`

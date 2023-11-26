[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-symfony/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Symfony)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-symfony)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-symfony/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-symfony/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-symfony/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-symfony/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Symfony auto-instrumentation

This is an OpenTelemetry auto-instrumentation package for Symfony framework applications.

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Requirements

* [OpenTelemetry extension](https://opentelemetry.io/docs/instrumentation/php/automatic/#installation)
* OpenTelemetry SDK and exporters (required to actually export traces)

## Overview
The following features are supported:
* root span creation (`Symfony\Component\HttpKernel\HttpKernel::handle` hook)
* context propagation
* HttpClient client span creation
* HTTPClient context propagation
* Message Bus span creation
* Message Transport span creation

## Installation via composer

```bash
$ composer require open-telemetry/opentelemetry-auto-symfony
```

## Installing dependencies and executing tests

From Symfony subdirectory:

```bash
$ composer install
$ ./vendor/bin/phpunit tests
```

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=symfony
```

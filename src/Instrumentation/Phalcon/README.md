[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-phalcon/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Phalcon)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-phalcon)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-phalcon/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-phalcon/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-phalcon/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-phalcon/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Phalcon auto-instrumentation

This is an OpenTelemetry auto-instrumentation package for applications built on the
[Phalcon](https://phalcon.io/) PHP framework.

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Requirements

* PHP >= 8.2 — `hook()` observes internal/extension functions (what every method on compiled
  Phalcon classes is) via Zend Observer API support added in PHP 8.2; on 8.1 hooks register but
  never fire.
* [OpenTelemetry extension](https://opentelemetry.io/docs/instrumentation/php/automatic/#installation)
* OpenTelemetry SDK and exporters (required to actually export traces)
* [Phalcon](https://phalcon.io/) 5.x (`ext-phalcon`)

## Overview

A request can reach Phalcon through several different entry points, and no single one of them
covers every app — auto-instrumentation hooks all of them so exactly one root span is created
regardless of which style the application uses:

* `Phalcon\Mvc\Application::handle()` — full MVC apps.
* `Phalcon\Mvc\Micro::handle()` — Micro apps, which route straight to a handler callable and
  never touch a dispatcher.
* `Phalcon\Cli\Console::handle()` — CLI tasks (`INTERNAL` span, no HTTP attributes).
* `Phalcon\Dispatcher\AbstractDispatcher::dispatch()` — fallback root, only when none of the
  above already started one (e.g. an app building and dispatching a `Mvc\Dispatcher` directly,
  without a surrounding `Application`).

The root span is named after the matched route when the application uses named routes, its raw
pattern when it has one and isn't a raw-regex default route, and falls back to
`{controller}/{action}` (`{task}/{action}` for CLI) otherwise.

`AbstractDispatcher::callActionMethod()` produces one child span (`INTERNAL` kind) per controller
action actually invoked, including one for a `forward()`-ed action (Phalcon's `forward()` doesn't
re-enter `dispatch()`; it makes `dispatch()`'s own internal loop run `callActionMethod()` again
for the new action) and for an app manually re-dispatching to resolve a nested resource.

## Installation via composer

```bash
$ composer require open-telemetry/opentelemetry-auto-phalcon
```

## Installing dependencies and executing tests

From the Phalcon subdirectory:

```bash
$ composer install
$ ./vendor/bin/phpunit tests
```

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=phalcon
```

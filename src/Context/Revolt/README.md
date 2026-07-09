[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/context-revolt/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Context/Revolt)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-revolt-adapter/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-revolt-adapter/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-revolt-adapter/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-revolt-adapter/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Revolt EventLoop adapter

Propagates the current [`open-telemetry/context`](https://github.com/opentelemetry-php/context) to
[`revolt/event-loop`](https://github.com/revoltphp/event-loop) callbacks.

```php
$context = Context::getCurrent();
EventLoop::queue(fn() => assert($context === Context::getCurrent()));
```

## Installation

```shell
composer require open-telemetry/opentelemetry-revolt-adapter
```

## Usage

The adapter is automatically applied to the global event loop.  
If you use a local event loop or replace the initial driver using `EventLoop::setDriver()`, you must manually wrap the
driver using `RevoltDriver::wrap()`.

## Metrics

When the `open-telemetry/api` package is installed, this package emits the following metrics for the global event loop.

### Metric: `php.revolt.eventloop.callbacks`

| Name                             | Instrument Type | Unit (UCUM)  | Description                                   |
|----------------------------------|-----------------|--------------|-----------------------------------------------|
| `php.revolt.eventloop.callbacks` | UpDownCounter   | `{callback}` | The number of registered event loop callbacks |

#### Attributes

| Key                                   | Value Type | Example Values                                               |
|---------------------------------------|------------|--------------------------------------------------------------|
| `php.revolt.eventloop.callback.type`  | string     | `defer`; `delay`; `repeat`; `readable`; `writable`; `signal` |
| `php.revolt.eventloop.callback.state` | string     | `referenced`; `unreferenced`; `disabled`                     |

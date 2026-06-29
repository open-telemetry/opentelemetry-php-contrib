[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-magento2/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Magento2)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-magento2)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-magento2/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-magento2/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-magento2/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-magento2/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Magento2 auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via composer, and spans will automatically be created for:
- `Http::launch()` - creates the root HTTP server span, attaches request attributes, records the response status code, propagates response headers, and records exceptions.
- `Bootstrap::terminate()` - creates a `Bootstrap::terminate` span and records any terminating exception.
- `FrontController::dispatch()` - creates a `FrontController.dispatch` span around front controller routing.
- `Action::dispatch()` - creates a span named from the Magento full action name, or `unknown` when it is unavailable.
- `ActionInterface::execute()` - creates an `ActionInterface.execute` span around action execution.
- `Manager::dispatch()` - creates `EVENT: {event name}` spans for Magento event dispatches, with `unknown` as a fallback.
- `InvokerInterface::dispatch()` - creates `OBSERVER: {observer name}` spans for observer execution, with `unknown` as a fallback.
- `Template::fetchView()` - creates `TEMPLATE: {template path}` spans for template rendering and records rendering exceptions.
- `View::renderLayout()` - creates a `LAYOUT: layout_render` span around layout rendering and records rendering exceptions.

In addition to spans, `Http::launch()` also records the `http.server.request.duration` metric with request metadata and response/error attributes.

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=magento2
```

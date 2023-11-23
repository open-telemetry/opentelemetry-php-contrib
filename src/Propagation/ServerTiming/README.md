[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-propagator-server-timing/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Propagation/ServerTiming)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-propagator-server-timing)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-propagation-traceresponse/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-propagation-server-timing/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-propagation-traceresponse/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-propagation-server-timing/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry ServerTiming Propagator

This package provides a [Server-Timing](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Server-Timing)
propagator to inject the current span context into Response datastructures.

The main goal is to allow client-side technology (Real User Monitoring, HTTP Clients) to record
the server side context in order to allow referencing it.

Server-Timing response headers are especially useful for this approach, as they are accessible on the client side,
even for the initial page load.

## Requirements

* OpenTelemetry SDK and exporters (required to actually export traces)

Optional:
* OpenTelemetry extension (Some instrumentations can automatically use the `TraceResponsePropagator`)

## Usage

Assuming there is an active `SpanContext`, you can inject it into your response as follows:

```php
// your framework probably provides a datastructure to model HTTP responses
// and allows you to hook into the end of a request / listen to a matching event.
$response = new Response();

// get the current scope, bail out if none
$scope = Context::storage()->scope();
if (null === $scope) {
    return;
}

// create a PropagationSetterInterface that knows how to inject response headers
$propagationSetter = new class implements OpenTelemetry\Context\Propagation\PropagationSetterInterface {
    public function set(&$carrier, string $key, string $value) : void {
        $carrier->headers->set($key, $value);
    }
};
$propagator = new ServerTimingPropagator();
$propagator->inject($response, $propagationSetter, $scope->context());
```

## Installation via composer

```bash
$ composer require open-telemetry/opentelemetry-propagation-server-timing
```

## Installing dependencies and executing tests

From TraceResponse subdirectory:

```bash
$ composer install
$ ./vendor/bin/phpunit tests
```

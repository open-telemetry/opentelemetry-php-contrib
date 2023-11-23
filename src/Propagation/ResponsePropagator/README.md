[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-propagator-traceresponse/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Propagation/TraceResponse)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-propagator-traceresponse)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-propagation-traceresponse/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-propagation-traceresponse/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-propagation-traceresponse/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-propagation-traceresponse/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry TraceResponse Propagator

**Note:** This package is experimental as `traceresponse` is currently an editors' draft.

This package provides a [Trace Context HTTP Response Headers Format](https://w3c.github.io/trace-context/#trace-context-http-response-headers-format)
propagator to inject the current span context into Response datastructures.

The main goal is to allow client-side technology (Real User Monitoring, HTTP Clients) to record
the server side context in order to allow referencing it.

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
$propagator = new TraceResponseProgator();
$propagator->inject($response, $propagationSetter, $scope->context());
```

## Installation via composer

```bash
$ composer require open-telemetry/opentelemetry-propagation-traceresponse
```

## Installing dependencies and executing tests

From TraceResponse subdirectory:

```bash
$ composer install
$ ./vendor/bin/phpunit tests
```

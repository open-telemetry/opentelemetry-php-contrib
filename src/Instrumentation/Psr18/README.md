# OpenTelemetry PSR-18 auto-instrumentation

## Requirements

* OpenTelemetry extension
* OpenTelemetry SDK and exporters (required to actually export traces)
* A psr-18 client

## Overview
Auto-instrumentation hooks are registered via composer, and spans will automatically be created for each PSR-18 request that is sent.
Also, the request will automatically have a `traceparent` header added to facilitate distributed tracing.

To export spans, you will need to create and register a `TracerProvider` early in your application's lifecycle:

```php
<?php
require_once 'vendor/autoload.php';

$tracerProvider = /*create tracer provider*/;
$scope = \OpenTelemetry\API\Common\Instrumentation\Configurator::create()
    ->withTracerProvider($tracerProvider)
    ->activate();

$client->sendRequest($request);

$scope->detach();
$tracerProvider->shutdown();
```

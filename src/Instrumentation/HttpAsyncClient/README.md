# OpenTelemetry HTTPlug async auto-instrumentation

## Requirements

* OpenTelemetry extension
* OpenTelemetry SDK an exporter (required to actually export traces)
* An HTTPlug async client
* (optional) OpenTelemetry [SDK Autoloading](https://github.com/open-telemetry/opentelemetry-php/blob/main/examples/autoload_sdk.php) configured

## Overview
Auto-instrumentation hooks are registered via composer, which will:

* create spans automatically for each async request that is sent

## Manual configuration
If you are not using SDK autoloading, you will need to create and register a `TracerProvider` early in your application's lifecycle:

```php
<?php
require_once 'vendor/autoload.php';

$tracerProvider = /*create tracer provider*/;
$scope = \OpenTelemetry\API\Common\Instrumentation\Configurator::create()
    ->withTracerProvider($tracerProvider)
    ->activate();

$client->sendAsyncRequest($request);

$scope->detach();
$tracerProvider->shutdown();
```
## Installation via composer

```bash
$ composer require open-telemetry/opentelemetry-auto-http-async
```
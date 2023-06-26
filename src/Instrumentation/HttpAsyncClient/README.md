# OpenTelemetry HTTPlug async auto-instrumentation

**Preferred and simplest way to install auto-instrumentation (c extension plus instrumentation libraries) is to use [opentelemetry-instrumentation-installer](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/AutoInstrumentationInstaller).**
**The same process can be done manually by installing [c extension](https://github.com/open-telemetry/opentelemetry-php-instrumentation#installation) plus all needed instrumentation libraries like [HttpAsyncClient](#Installation-via-composer)**

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
$scope = \OpenTelemetry\API\Instrumentation\Configurator::create()
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

## Configuration

Parts of this auto-instrumentation library can be configured, more options are available throught the 
[General SDK Configuration](https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/sdk-environment-variables.md#general-sdk-configuration):

| Name                                | Default value | Values                  | Example           | Description                                                                     |
|-------------------------------------|---------------|-------------------------|-------------------|---------------------------------------------------------------------------------|
| OTEL_PHP_DISABLED_INSTRUMENTATIONS  | []            | Instrumentation name(s) | http-async-client | Disable one or more installed auto-instrumentations, names are comma seperated. |

Configurations can be provided as environment variables, or via `php.ini` (or a file included by `php.ini`)

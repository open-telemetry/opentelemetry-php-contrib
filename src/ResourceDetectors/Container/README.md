# OpenTelemetry Container Detector

This package provides an OpenTelemetry `ResourceDetector` which will detect docker container id at runtime, using either V1 (cgroup) or V2 (mountinfo).

## Requirements

* OpenTelemetry SDK

## Installation via composer

```bash
$ composer require open-telemetry/detector-container
```

## Usage

The detector will be automatically registered as part of composer autoloading.

By default, all built-in and registered custom resource detectors are used, and will be added to the default resources associated with traces, metrics, and logs.

You can also provide a list of detectors via the `OTEL_PHP_DETECTORS` config (environment variable or php.ini setting):
```php
putenv('OTEL_PHP_DETECTORS=container,env,os,<others>')

var_dump(ResourceInfoFactory::defaultResource());
```

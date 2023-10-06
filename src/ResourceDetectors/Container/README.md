[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-detector-container/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/ResourceDetectors/Container)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-detector-container)
[![Latest Version](http://poser.pugx.org/open-telemetry/detector-container/v/unstable)](https://packagist.org/packages/open-telemetry/detector-container/)
[![Stable](http://poser.pugx.org/open-telemetry/detector-container/v/stable)](https://packagist.org/packages/open-telemetry/detector-container/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Container Detector

This package provides an OpenTelemetry `ResourceDetector` which will detect docker container id at runtime, using either V1 (cgroup) or V2 (mountinfo).
It should work with docker, kubernetes, and podman containers.

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

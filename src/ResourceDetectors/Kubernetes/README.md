[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-aws/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Azure)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/detector-azure)
[![Latest Version](http://poser.pugx.org/open-telemetry/detector-azure/v/unstable)](https://packagist.org/packages/open-telemetry/detector-azure/)
[![Stable](http://poser.pugx.org/open-telemetry/detector-azure/v/stable)](https://packagist.org/packages/open-telemetry/detector-azure/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Kubernetes Resource Detectors

This package provides OpenTelemetry `ResourceDetector`s which will detect a stable service instance id.

## Installation via composer

```bash
$ composer require open-telemetry/detector-k8s
```

## Usage

The detector will be automatically registered as part of composer autoloading.

By default, all built-in and registered custom resource detectors are used, and will be added to the default resources associated with traces, metrics, and logs.

You can also provide a list of detectors via the `OTEL_PHP_DETECTORS` config (environment variable or php.ini setting):
```php
putenv('OTEL_PHP_DETECTORS=k8s,env,os,<others>')

var_dump(ResourceInfoFactory::defaultResource());
```

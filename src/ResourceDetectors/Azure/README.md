[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-aws/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Azure)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/detector-azure)
[![Latest Version](http://poser.pugx.org/open-telemetry/detector-azure/v/unstable)](https://packagist.org/packages/open-telemetry/detector-azure/)
[![Stable](http://poser.pugx.org/open-telemetry/detector-azure/v/stable)](https://packagist.org/packages/open-telemetry/detector-azure/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Azure Resource Detectors

This package provides OpenTelemetry `ResourceDetector`s which will detect 
resource attributes for these Azure services:
* App Service
* Container Apps
* Virtual Machines

The following OpenTelemetry resource attributes will be detected:

| Resource attribute      | VM       | App Service       | Containers           |
| cloud.platform          | azure_vm | azure_app_service | azure_container_apps |
| cloud.provider          | azure    | azure             | azure                |
| cloud.resource.id       | auto     | auto              |                      |
| cloud.region            | auto     | auto              |                      |
| deployment.environment  |          | auto              |                      |
| host.id                 | auto     | auto              |                      |
| host.name               | auto     |                   |                      |
| host.type               | auto     |                   |                      |
| os.type                 | auto     |                   |                      |
| os.version              | auto     |                   |                      |
| azure.vm.scaleset.name  | auto     |                   |                      |
| azure.vm.sku            | auto     |                   |                      |
| service.name            |          | auto              | auto                 |
| service.version         |          |                   | auto                 |
| service.instance.id     |          | auto              | auto                 |
| azure.app.service.stamp |          | auto              |                      |

## Requirements

* OpenTelemetry SDK

## Installation via composer

```bash
$ composer require open-telemetry/detector-azure
```

## Usage

The detector will be automatically registered as part of composer autoloading.

By default, all built-in and registered custom resource detectors are used, and will be added to the default resources associated with traces, metrics, and logs.

You can also provide a list of detectors via the `OTEL_PHP_DETECTORS` config (environment variable or php.ini setting):
```php
putenv('OTEL_PHP_DETECTORS=azure,env,os,<others>')

var_dump(ResourceInfoFactory::defaultResource());
```

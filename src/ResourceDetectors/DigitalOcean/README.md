[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-detector-digitalocean/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/ResourceDetectors/DigitalOcean)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-detector-digitalocean)
[![Latest Version](http://poser.pugx.org/open-telemetry/detector-digitalocean/v/unstable)](https://packagist.org/packages/open-telemetry/detector-digitalocean/)
[![Stable](http://poser.pugx.org/open-telemetry/detector-digitalocean/v/stable)](https://packagist.org/packages/open-telemetry/detector-digitalocean/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry DigitalOcean Resource Detector

This package provides an OpenTelemetry `ResourceDetector` which will detect
resource attributes for DigitalOcean Droplets.

The following OpenTelemetry resource attributes will be detected:

| Resource attribute      | Droplet                |
|-------------------------|------------------------|
| cloud.account.id        | auto*                  |
| cloud.availability_zone |                        |
| cloud.platform          | `digitalocean_droplet` |
| cloud.provider          | `digitalocean`         |
| cloud.region            | auto                   |
| cloud.resource.id       | auto                   |

_*If a DigitalOcean API personal access token, with the account:read scope, is available to PHP via the `DIGITALOCEAN_ACCESS_TOKEN` environment variable, this resource detector will attempt to read the team UUID for that token and store it as the `cloud.account.id` resource attribute. This has no impact on the other attributes._

## Requirements

* OpenTelemetry SDK

## Installation via composer

```bash
$ composer require open-telemetry/detector-digitalocean
```

## Usage

The detector will be automatically registered as part of Composer autoloading.

By default, all built-in and registered custom resource detectors are used, and will be added to the default resources associated with traces, metrics, and logs.

You can also provide a list of detectors via the `OTEL_PHP_DETECTORS` config (environment variable or php.ini setting):
```php
putenv('OTEL_PHP_DETECTORS=host,digitalocean,process')

var_dump(ResourceInfoFactory::defaultResource());
```

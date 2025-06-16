[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-detector-digitalocean/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/ResourceDetectors/DigitalOcean)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-detector-digitalocean)
[![Latest Version](http://poser.pugx.org/open-telemetry/detector-digitalocean/v/unstable)](https://packagist.org/packages/open-telemetry/detector-digitalocean/)
[![Stable](http://poser.pugx.org/open-telemetry/detector-digitalocean/v/stable)](https://packagist.org/packages/open-telemetry/detector-digitalocean/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry DigitalOcean resource detector

Please see https://opentelemetry.io/docs/languages/php/resources/#custom-resource-detectors for installation and configuration.

## Overview

This package provides an OpenTelemetry `ResourceDetector` which will detect
resource attributes for DigitalOcean Droplets.

The following OpenTelemetry resource attributes will be detected:

| Resource attribute        | Droplet                           |
|---------------------------|-----------------------------------|
| `cloud.account.id`        | auth[^1] (scope `account:read`)   |
| `cloud.availability_zone` | _not applicable to DigitalOcean_  |
| `cloud.platform`          | auto                              |
| `cloud.provider`          | auto                              |
| `cloud.region`            | auto                              |
| `cloud.resource.id`       | auto                              |
| `host.arch`               | static (`amd64`)                  |
| `host.id`                 | auto                              |
| `host.image.id`           | auth[^1] (scope `droplet:read`)   |
| `host.image.name`         | auth[^1] (scope `droplet:read`)   |
| `host.image.version`      | _not applicable to DigitalOcean_  |
| `host.ip`                 | omitted[^2]                       |
| `host.mac`                | omitted[^2]                       |
| `host.name`               | auto                              |
| `host.type`               | auth[^1] (scope `droplet:read`)   |
| `os.name`[^3]             | auth[^1] (scope `droplet:read`)   |
| `os.type`[^3]             | static (`linux`)                  |

[^1]: If a DigitalOcean API personal access token, with the listed scope, is available to PHP via the `DIGITALOCEAN_ACCESS_TOKEN` environment variable, this resource detector will attempt to read the corresponding values from the API. This has no impact on the other attributes.
[^2]: These attributes are marked as `Opt-In` within the OpenTelemetry semantic conventions, meaning they should _not_ be included unless the user configures the instrumentation to do so. It is a future todo for this library to support configuration.
[^3]: These attributes should be combined with a resource detector that includes all of the `os` resource attributes, but if these attributes are known, they will be provided.

## Configuration

By default, all installed resource detectors are used, and the attributes they detect will be added to the default resources associated with traces, metrics, and logs.

You can also provide a list of specific detectors via the `OTEL_PHP_DETECTORS` config (environment variable or `php.ini` setting):

```shell
OTEL_PHP_DETECTORS="host,process,digitalocean"
```

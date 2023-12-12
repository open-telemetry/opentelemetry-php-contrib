[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-psr14/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Psr14)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-psr14)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-psr14/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-psr14/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-psr14/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-psr14/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry PSR-14 auto-instrumentation
Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.
 
## Overview
Auto-instrumentation hooks are registered via composer, and spans will automatically be created for each PSR-14 
dispatched event.

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=psr14
```

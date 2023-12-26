[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-yii/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Yii)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-yii)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-yii/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-yii/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-yii/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-yii/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Yii auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview

Requires Yii 2.0.13+

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=yii
```

## Examples

you can find examples on how to use the Yii auto-instrumentation in the [examples directory](/examples/instrumentation/yii/README.md).
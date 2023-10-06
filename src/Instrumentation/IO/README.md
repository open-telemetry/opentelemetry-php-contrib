[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-io/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Io)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-io)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-io/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-io/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-io/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-io/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry IO auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via composer, and spans will automatically be created for the
following functions:
- `fopen`
- `fwrite`
- `fread`
- `file_get_contents`
- `file_put_contents`
- `curl_init`
- `curl_exec`

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=io
```
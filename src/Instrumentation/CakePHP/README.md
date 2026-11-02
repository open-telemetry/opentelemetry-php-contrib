
This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry CakePHP auto-instrumentation

[![CI](https://github.com/open-telemetry/opentelemetry-php-contrib/actions/workflows/project-instrumentation-cakephp.yml/badge.svg?branch=main)](https://github.com/open-telemetry/opentelemetry-php-contrib/actions/workflows/project-instrumentation-cakephp.yml?query=branch%3Amain)

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via composer, and spans will automatically be created for:
- Controller invoke

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=cakephp
```

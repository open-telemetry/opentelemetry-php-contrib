[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-openai/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/OpenAIPHP)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-openai)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-openai-php/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-openai-php/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-openai-php/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-openai-php/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry openai-php/client auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview

This package provides auto-instrumentation for [openai-php/client](https://packagist.org/packages/openai-php/client) to help
you understand the interactions with OpenAI compatible services.

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=openaiphp
```

[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-mysqli/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/MySqli)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-mysqli)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-mysqli/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-mysqli/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-mysqli/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-mysqli/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry mysqli auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via composer, and client kind spans will automatically be created when calling following functions or methods:

* `mysqli_connect`
* `mysqli::__construct`
* `mysqli::connect`
* `mysqli_real_connect`
* `mysqli::real_connect`

* `mysqli_query`
* `mysqli::query`
* `mysqli_real_query`
* `mysqli::real_query`
* `mysqli_execute_query`
* `mysqli::execute_query`
* `mysqli_multi_query`
* `mysqli::multi_query`
* `mysqli_next_result`
* `mysqli::next_result`

* `mysqli_begin_transaction`
* `mysqli::begin_transaction`
* `mysqli_rollback`
* `mysqli::rollback`
* `mysqli_commit`
* `mysqli::commit`
*
* `mysqli_stmt_execute`
* `mysqli_stmt::execute`
* `mysqli_stmt_next_result`
* `mysqli_stmt::next_result`

## Configuration

### Disabling mysqli instrumentation

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=mysqli
```


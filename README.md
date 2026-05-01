# OpenTelemetry php contrib library

![CI Build](https://github.com/open-telemetry/opentelemetry-php-contrib/workflows/PHP%20QA/badge.svg)
[![codecov](https://codecov.io/gh/open-telemetry/opentelemetry-php-contrib/branch/main/graph/badge.svg)](https://codecov.io/gh/open-telemetry/opentelemetry-php-contrib)

## Current Project Status
For more information, please, consult the documentation of the main [OpenTelemetry PHP project][opentelemetry-php].

## Issues

Issues have been disabled for this repo in order to help maintain consistency between this repo and the main [opentelemetry-php] repo. If you have an issue you'd like to raise about this issue, please use the [OpenTelemetry PHP Issue section](https://github.com/open-telemetry/opentelemetry-php/issues/new/choose). Please prefix the title of the issue with [opentelemetry-php-contrib].

## Installation

### Install individual packages:

(This is the recommended way to install the components)

Refer to the documentation for the individual components on how to install them

- [AWS](/src/Aws/README.md)
- [Symfony SdkBundle](/src/Symfony/README.md)

## Usage/Examples

### Auto-instrumentation

Auto-instrumentation requires the [ext-opentelemetry] PHP extension, and
the installation of one or more packages from [src/Instrumentation](./src/Instrumentation)

### AWS

- You can find examples on how to use the AWS classes in the [examples directory](/examples/aws/README.md).

### Symfony

#### SdkBundle

- The documentation for the Symfony SdkBundle can be found [here](/src/Symfony/README.md).
- An example Symfony application using the SdkBundle can be found [here](https://github.com/opentelemetry-php/otel-sdk-bundle-example-sf5).

### Swoole

- The documentation for the Swoole context can be found [here](/src/Context/Swoole/README.md).

### Yii

- The documentation for Yii framework can be found [here](/src/Instrumentation/Yii/README.md).

## Development

Please, consult the documentation of the main [OpenTelemetry PHP project][opentelemetry-php].

### Subprojects

This repository is organized into multiple separate sub-projects, under `/src`.
Please remember to run all tests as you develop, the makefile supports a `PROJECTS` variable, which corresponds to the path of the project (relative to `src/`), eg

```
$ PROJECT=Symfony PHP_VERSION=8.1 make all
```

<!-- References -->

[opentelemetry-php]: https://github.com/open-telemetry/opentelemetry-php
[ext-opentelemetry]: https://pecl.php.net/package/opentelemetry

### Emeritus

- [Ago Allikmaa](https://github.com/agoallikmaa), Approver
- [Amber Sistla](https://github.com/zsistla), Triager
- [Beniamin](https://github.com/beniamin), Triager
- [Fahmy Mohammed](https://github.com/Fahmy-Mohammed), Triager
- [Jodee Varney](https://github.com/jodeev), Triager
- [Kishan Sangani](https://github.com/kishannsangani), Triager
- [Levi Morrison](https://github.com/morrisonlevi), Triager
- [Przemyslaw Delewski](https://github.com/pdelewski), Triager
- [Timo Michna](https://github.com/tidal), Triager

For more information about the emeritus role, see the
[community repository](https://github.com/open-telemetry/community/blob/main/guides/contributor/membership.md#emeritus-maintainerapprovertriager).

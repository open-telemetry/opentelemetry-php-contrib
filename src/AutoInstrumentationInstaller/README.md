# Install auto instrumentation with one command.

This directory contains two scripts that helps install auto-instrumentation support and run application.

First script `install-otel-instrumentation` will install all needed dependencies for auto-instrumentation. Second `run-with-otel-instrumentation` is responsible for setting few environment variables (that are needed to export traces into backend) and running application.

Install script works in two modes:

- basic (will install defaults)
- advanced (interactive mode, you will control whole process)

## Example workflow

This section shows how to install and run auto-instrumented application which uses Slim framework.
To generate application, we follow steps described here: https://www.slimframework.com/.

```bash
    composer create-project slim/slim-skeleton:dev-master slimauto
    cd slimauto
    composer require open-telemetry/opentelemetry-instrumentation-installer
    ./vendor/bin/install-otel-instrumentation basic beta
    ./vendor/bin/run-with-otel-instrumentation php -S localhost:8080 -t public public/index.php
```

# OpenTelemetry PHP metapackage

[![Total Downloads](http://poser.pugx.org/open-telemetry/opentelemetry/downloads)](https://packagist.org/packages/open-telemetry/opentelemetry)

This is a metapackage which provides:
- the OpenTelemetry API and SDK
- common HTTP-based exporters (OTLP and zipkin)
- an HTTP factory (nyholm/psr7)
- an HTTP client (symfony/http-client)

This meta-package is useful to try out OpenTelemetry for PHP, but for production use we recommend requiring the packages/versions
you need directly in your composer.json file.

The version of this meta-package does not align with any particular OpenTelemetry package versions.

This is a read-only repository, please file issues and PRs at https://github.com/open-telemetry/opentelemetry-php
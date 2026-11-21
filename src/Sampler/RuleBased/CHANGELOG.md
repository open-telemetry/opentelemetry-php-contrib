# Changelog

Notable changes to `open-telemetry/sampler-rule-based`. Loosely follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), with a Breaking changes section first so
upgraders can check them in one place.

## Unreleased

### Breaking changes

- Requires PHP 8.2 and `open-telemetry/sdk-configuration` 0.10. Configuration files must use
  `file_format: "1.0"` ([#775]).
- The component providers are registered under the
  `OpenTelemetry\API\Configuration\Config\ComponentProvider` SPI key, and implement the interfaces in
  `OpenTelemetry\API\Configuration\Config` ([#775]).

## 0.3.0 and earlier

See the [GitHub releases](https://github.com/opentelemetry-php/contrib-sampler-rulebased/releases).

[#775]: https://github.com/open-telemetry/opentelemetry-php-contrib/pull/775

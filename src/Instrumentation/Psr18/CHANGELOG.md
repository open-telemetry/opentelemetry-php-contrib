# Changelog

Notable changes to `open-telemetry/opentelemetry-auto-psr18`. Loosely follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), with a Breaking changes section first so
upgraders can check them in one place.

## Unreleased

### Breaking changes

- The instrumentation is registered through SPI, and the SDK autoloader loads it. It needs
  `OTEL_PHP_AUTOLOAD_ENABLED=true`, and is no longer registered when you set up the SDK yourself
  ([#751]).
- `Psr18Instrumentation::register()` is no longer static, and `Psr18Instrumentation::NAME` is
  removed ([#751]).
- Requires `open-telemetry/api` 1.10 and `tbachert/spi` 1.0.5 ([#751]).

### Added

- Declarative configuration, under `instrumentation/development` as `php.psr18`. Set
  `enabled: false` to turn it off ([#751]).

### Changed

- `ext-opentelemetry` is suggested, not required. Without it the instrumentation is skipped. It used
  to raise a warning ([#751]).

## 1.4.0 and earlier

See the [GitHub releases](https://github.com/opentelemetry-php/contrib-auto-psr18/releases).

[#751]: https://github.com/open-telemetry/opentelemetry-php-contrib/pull/751

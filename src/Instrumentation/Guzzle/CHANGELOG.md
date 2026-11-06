# Changelog

Notable changes to `open-telemetry/opentelemetry-auto-guzzle`. Loosely follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), with a Breaking changes section first so
upgraders can check them in one place.

## Unreleased

### Fixed

- Instrumented requests no longer trigger a deprecation of `GuzzleHttp\Promise\Is` with
  `guzzlehttp/promises` 3.1, which Guzzle 8.3 requires ([#765]).

## 1.5.0 and earlier

See the [GitHub releases](https://github.com/opentelemetry-php/contrib-auto-guzzle/releases).

[#765]: https://github.com/open-telemetry/opentelemetry-php-contrib/pull/765

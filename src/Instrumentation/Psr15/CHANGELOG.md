# Changelog

Notable changes to `open-telemetry/opentelemetry-auto-psr15`. Loosely follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), with a Breaking changes section first so
upgraders can check them in one place.

## Unreleased

### Fixed

- Spans for anonymous classes, such as Slim's middleware handlers, are now named `<parent>@anonymous::<method>`.
  The span name and `code.function.name` used to include a NUL byte, the file and line, and a counter that changes each
  time the file is compiled, which created a new span name over and over. ([#771](https://github.com/open-telemetry/opentelemetry-php-contrib/pull/771))

## 1.3.1 and earlier

See the [GitHub releases](https://github.com/opentelemetry-php/contrib-auto-psr15/releases).

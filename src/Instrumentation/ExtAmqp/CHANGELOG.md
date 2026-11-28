# Changelog

Notable changes to `open-telemetry/opentelemetry-auto-ext-amqp`. Loosely follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), with a Breaking changes section first so
upgraders can check them in one place.

## Unreleased

## 0.2.0 - 2026-11-28

### Breaking changes

- Requires `open-telemetry/sem-conv` 1.45 ([#772]).
- Spans set `messaging.system.name` instead of `messaging.system`, and `rabbitmq.destination.routing_key` instead of
  `messaging.rabbitmq.destination.routing_key`, following semantic conventions 1.45. Update queries and dashboards
  that use the old attributes ([#772]).
- The instrumentation scope reports schema URL 1.45.0 instead of 1.38.0 ([#772]).

### Fixed

- Publish and consume hooks no longer fail with an undefined `MessagingIncubatingAttributes` constant when
  `open-telemetry/sem-conv` 1.45 is installed ([#772]).

## 0.1.0 and earlier

See the [GitHub releases](https://github.com/opentelemetry-php/contrib-auto-ext-amqp/releases).

[#772]: https://github.com/open-telemetry/opentelemetry-php-contrib/pull/772

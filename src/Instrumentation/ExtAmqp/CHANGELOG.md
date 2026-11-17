# Changelog

Notable changes to `open-telemetry/opentelemetry-auto-ext-amqp`. Loosely follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), with a Breaking changes section first so
upgraders can check them in one place.

## Unreleased

### Breaking changes

- Requires `open-telemetry/sem-conv` 1.45 ([#772]).
- Spans set `messaging.system.name` instead of `messaging.system`, and `rabbitmq.destination.routing_key` instead of
  `messaging.rabbitmq.destination.routing_key`, following semantic conventions 1.45. Update queries and dashboards
  that use the old attributes ([#772]).

### Fixed

- Publish and consume hooks no longer fail with an undefined `MessagingIncubatingAttributes` constant when
  `open-telemetry/sem-conv` 1.45 is installed ([#772]).

## 0.1.0 and earlier

See the [GitHub releases](https://github.com/opentelemetry-php/contrib-auto-ext-amqp/releases).

[#772]: https://github.com/open-telemetry/opentelemetry-php-contrib/pull/772

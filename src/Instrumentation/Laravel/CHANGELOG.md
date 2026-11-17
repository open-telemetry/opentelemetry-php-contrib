# Changelog

Notable changes to `open-telemetry/opentelemetry-auto-laravel`. Loosely follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), with a Breaking changes section first so
upgraders can check them in one place.

## Unreleased

### Breaking changes

- Requires `open-telemetry/sem-conv` 1.45 ([#772]).
- Queue spans set `messaging.system.name` instead of `messaging.system`, and SQS jobs report `aws.sqs` instead of
  `aws_sqs`, following semantic conventions 1.45. Update queries and dashboards that use the old attribute ([#772]).

### Fixed

- Queue hooks no longer fail with `Undefined constant MessagingIncubatingAttributes::MESSAGING_SYSTEM` when
  `open-telemetry/sem-conv` 1.45 is installed, so queue spans are recorded again ([#772]).

## 1.10.0 and earlier

See the [GitHub releases](https://github.com/opentelemetry-php/contrib-auto-laravel/releases).

[#772]: https://github.com/open-telemetry/opentelemetry-php-contrib/pull/772

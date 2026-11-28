# Changelog

Notable changes to `open-telemetry/opentelemetry-auto-symfony`. Loosely follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), with a Breaking changes section first so
upgraders can check them in one place.

## Unreleased

## 1.7.1 - 2026-11-28

### Fixed

- HttpClient spans report the absolute URL and `peer.service` when the client resolves a relative URL
  against its `base_uri` option ([#773]).
- Credentials in `url.full` are redacted as `REDACTED:REDACTED` ([#773]).
- A followed redirect no longer ends the HttpClient span on the 3xx: the span carries the final
  status ([#773]).

## 1.7.0 and earlier

See the [GitHub releases](https://github.com/opentelemetry-php/contrib-auto-symfony/releases).

[#773]: https://github.com/open-telemetry/opentelemetry-php-contrib/pull/773

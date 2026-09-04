[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-laravel/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Laravel)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-laravel)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-laravel/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-laravel/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-laravel/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-laravel/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Laravel auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via composer, and spans will automatically be created.

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=laravel
```

### Long-running commands

No `Command` span is created for worker / daemon commands, because such a command only returns when
the process is stopped: the span would never end and would become the ambient parent for every job,
request and query handled during the process lifetime, collapsing everything into a single unbounded
trace. The work done inside each iteration (e.g. a queued job) is still traced on its own.

Laravel's own long-running commands are recognised out of the box: `queue:work`, `queue:listen`,
`horizon`, `horizon:work`, `horizon:supervisor`, `schedule:work`, `reverb:start`, `reverb:restart`,
`pail`, `octane:start`, `octane:frankenphp`, `octane:swoole`.

Add your own with a comma-separated list of names (`*` wildcards allowed), merged with the built-in
list:

```shell
OTEL_PHP_INSTRUMENTATION_LARAVEL_LONG_RUNNING_COMMANDS="app:kafka-consume,app:relay-*"
```

Or mark the command class with an attribute:

```php
use OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Console\LongRunningCommand;

#[LongRunningCommand]
class KafkaConsumeCommand extends Command
{
    // ...
}
```

The `Artisan handler` span (only created when `OTEL_PHP_TRACE_CLI_ENABLED=true`) is skipped for the
same commands. That span sees the command name only, so a custom command marked purely with
`#[LongRunningCommand]` must also be listed in the environment variable to be skipped there.

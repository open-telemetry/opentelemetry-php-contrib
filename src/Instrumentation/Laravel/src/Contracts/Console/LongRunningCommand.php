<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Console;

use Attribute;

/**
 * Marks an Artisan command as long-running (a worker / daemon / consumer loop).
 *
 * The console instrumentation does not open a `Command` span for a command
 * carrying this attribute, because such a command only returns when the process
 * stops. A never-ending span becomes the ambient parent for every job, request
 * or query handled during the process lifetime, producing a single unbounded
 * trace.
 *
 * Laravel's own long-running commands (`queue:work`, `horizon`, `octane:start`,
 * ...) are recognised by name out of the box; use this attribute for custom
 * commands, or the
 * `OTEL_PHP_INSTRUMENTATION_LARAVEL_LONG_RUNNING_COMMANDS` environment variable
 * for commands you cannot annotate.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class LongRunningCommand
{
}

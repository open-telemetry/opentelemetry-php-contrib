<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use OpenTelemetry\API\Instrumentation\ConfigurationResolver;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Console\LongRunningCommand;
use ReflectionAttribute;
use ReflectionClass;
use Throwable;

/**
 * Decides whether an Artisan command runs long enough that wrapping it in a
 * span is harmful.
 *
 * A worker / daemon command (`queue:work`, `horizon`, a custom consumer loop)
 * only returns when the process is stopped, so a span opened for its execution
 * never ends. While open it is the ambient parent for every job, request and
 * query handled by the process, collapsing the whole process lifetime into one
 * unbounded trace.
 *
 * A command is treated as long-running when its name matches the built-in list,
 * matches a pattern in the
 * `OTEL_PHP_INSTRUMENTATION_LARAVEL_LONG_RUNNING_COMMANDS` environment variable
 * (comma separated, `*` wildcards allowed, merged with the built-in list), or
 * the command class carries the
 * {@see \OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Console\LongRunningCommand}
 * attribute.
 */
final class LongRunningCommands
{
    public const OTEL_PHP_INSTRUMENTATION_LARAVEL_LONG_RUNNING_COMMANDS = 'OTEL_PHP_INSTRUMENTATION_LARAVEL_LONG_RUNNING_COMMANDS';

    /**
     * First-party Laravel / ecosystem commands that run until stopped.
     *
     * @var list<string>
     */
    private const DEFAULTS = [
        'queue:work',
        'queue:listen',
        'horizon',
        'horizon:work',
        'horizon:supervisor',
        'schedule:work',
        'reverb:start',
        'reverb:restart',
        'pail',
        'octane:start',
        'octane:frankenphp',
        'octane:swoole',
    ];

    public static function matches(?Command $command): bool
    {
        if ($command === null) {
            return false;
        }

        if (self::matchesName($command->getName())) {
            return true;
        }

        return self::hasAttribute($command);
    }

    public static function matchesName(?string $name): bool
    {
        if ($name === null || $name === '') {
            return false;
        }

        return Str::is(self::patterns(), $name);
    }

    /**
     * @return list<string>
     */
    private static function patterns(): array
    {
        $configured = array_map(
            'trim',
            (new ConfigurationResolver())->getList(self::OTEL_PHP_INSTRUMENTATION_LARAVEL_LONG_RUNNING_COMMANDS),
        );

        return array_values(array_filter(array_merge(self::DEFAULTS, $configured)));
    }

    private static function hasAttribute(Command $command): bool
    {
        try {
            return (new ReflectionClass($command))
                ->getAttributes(LongRunningCommand::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
        } catch (Throwable) {
            return false;
        }
    }
}

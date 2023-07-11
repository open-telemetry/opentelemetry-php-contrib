<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\MongoDB;

use function MongoDB\Driver\Monitoring\addSubscriber;
use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;

final class MongoDBInstrumentation
{
    public const NAME = 'mongodb';

    /**
     * @param callable(object):?string $commandSerializer
     */
    public static function register(callable $commandSerializer = null): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.mongodb');
        $commandSerializer ??= self::defaultCommandSerializer();
        /** @psalm-suppress UnusedFunctionCall */
        addSubscriber(new MongoDBInstrumentationSubscriber($instrumentation, $commandSerializer));
    }

    /**
     * @return callable(object):?string
     */
    private static function defaultCommandSerializer(): callable
    {
        return static fn (object $command): string => json_encode($command, JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\RedisCommand;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Str;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\Watcher;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\TraceAttributeValues;
use RangeException;
use RuntimeException;

/**
 * Watch the Redis Command event
 *
 * Call facade `Redis::enableEvents()` before using this watcher
 */
class RedisCommandWatcher extends Watcher
{
    public function __construct(
        private CachedInstrumentation $instrumentation,
    ) {
    }

    /** @psalm-suppress UndefinedInterfaceMethod */
    public function register(Application $app): void
    {
        /** @phan-suppress-next-line PhanTypeArraySuspicious */
        $app['events']->listen(CommandExecuted::class, [$this, 'recordRedisCommand']);
    }

    /**
     * Record a query.
     */
    /** @psalm-suppress UndefinedThisPropertyFetch */
    public function recordRedisCommand(CommandExecuted $event): void
    {
        $nowInNs = (int) (microtime(true) * 1E9);

        $operationName = Str::upper($event->command);

        /** @psalm-suppress ArgumentTypeCoercion */
        $span = $this->instrumentation->tracer()
            ->spanBuilder($operationName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($this->calculateQueryStartTime($nowInNs, $event->time))
            ->startSpan();

        // See https://opentelemetry.io/docs/specs/semconv/database/redis/
        $attributes = [
            TraceAttributes::DB_SYSTEM => TraceAttributeValues::DB_SYSTEM_REDIS,
            TraceAttributes::DB_NAME => $this->fetchDbIndex($event->connection),
            TraceAttributes::DB_OPERATION => $operationName,
            TraceAttributes::DB_QUERY_TEXT => Serializer::serializeCommand($event->command, $event->parameters),
            TraceAttributes::SERVER_ADDRESS => $this->fetchDbHost($event->connection),
        ];

        /** @psalm-suppress PossiblyInvalidArgument */
        $span->setAttributes($attributes);
        $span->end($nowInNs);
    }

    private function calculateQueryStartTime(int $nowInNs, float $queryTimeMs): int
    {
        return (int) ($nowInNs - ($queryTimeMs * 1E6));
    }

    private function fetchDbIndex(Connection $connection): int
    {
        if ($connection instanceof PhpRedisConnection) {
            $index = $connection->client()->getDbNum();

            if ($index === false) {
                throw new RuntimeException('Cannot fetch database index.');
            }

            return $index;
        } elseif ($connection instanceof PredisConnection) {
            /** @psalm-suppress PossiblyUndefinedMethod */
            $index = $connection->client()->getConnection()->getParameters()->database;

            if (is_int($index)) {
                throw new RuntimeException('Cannot fetch database index.');
            }

            return $index;
        }

        throw new RangeException('Unknown Redis connection instance: ' . get_class($connection));
        
    }

    private function fetchDbHost(Connection $connection): string
    {
        if ($connection instanceof PhpRedisConnection) {
            $host = $connection->client()->getHost();

            if ($host === false) {
                throw new RuntimeException('Cannot fetch database host.');
            }

            return $host;
        } elseif ($connection instanceof PredisConnection) {
            /** @psalm-suppress PossiblyUndefinedMethod */
            $host = $connection->client()->getConnection()->getParameters()->host;

            if (is_int($host)) {
                throw new RuntimeException('Cannot fetch database index.');
            }

            return $host;
        }

        throw new RangeException('Unknown Redis connection instance: ' . get_class($connection));
        
    }
}

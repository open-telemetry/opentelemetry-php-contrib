<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\RedisCommand;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Redis\Events\CommandExecuted;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\Watcher;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\DbIncubatingAttributes;
use Throwable;

/**
 * Watch the Redis Command event
 *
 * Call facade `Redis::enableEvents()` before using this watcher
 */
class RedisCommandWatcher extends Watcher
{
    public function __construct(
        private readonly TracerInterface $tracer,
    ) {
    }

    /** @psalm-suppress UndefinedInterfaceMethod */
    public function register(Application $app): void
    {
        /** @phan-suppress-next-line PhanTypeArraySuspicious */
        $app['events']->listen(CommandExecuted::class, [$this, 'recordRedisCommand']);
    }

    /**
     * Record a Redis command.
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function recordRedisCommand(CommandExecuted $event): void
    {
        $nowInNs = (int) (microtime(true) * 1E9);

        $operationName = strtoupper($event->command);

        /** @psalm-suppress ArgumentTypeCoercion */
        $span = $this->tracer
            ->spanBuilder($operationName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($this->calculateQueryStartTime($nowInNs, $event->time))
            ->startSpan();

        // See https://opentelemetry.io/docs/specs/semconv/database/redis/
        $attributes = [
            DbAttributes::DB_SYSTEM_NAME => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_REDIS,
            DbAttributes::DB_NAMESPACE => $this->fetchDbIndex($event->connection),
            DbAttributes::DB_OPERATION_NAME => $operationName,
            DbAttributes::DB_QUERY_TEXT => Serializer::serializeCommand($event->command, $event->parameters),
            ServerAttributes::SERVER_ADDRESS => $this->fetchDbHost($event->connection),
        ];

        /** @psalm-suppress PossiblyInvalidArgument */
        $span->setAttributes($attributes);
        $span->end($nowInNs);
    }

    private function calculateQueryStartTime(int $nowInNs, float $queryTimeMs): int
    {
        return (int) ($nowInNs - ($queryTimeMs * 1E6));
    }

    private function fetchDbIndex(Connection $connection): ?int
    {
        try {
            if ($connection instanceof PhpRedisConnection) {
                return $connection->client()->getDbNum();
            } elseif ($connection instanceof PredisConnection) {
                /** @psalm-suppress PossiblyUndefinedMethod */
                return $connection->client()->getConnection()->getParameters()->database;
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private function fetchDbHost(Connection $connection): ?string
    {
        try {
            if ($connection instanceof PhpRedisConnection) {
                return $connection->client()->getHost();
            } elseif ($connection instanceof PredisConnection) {
                /** @psalm-suppress PossiblyUndefinedMethod */
                return $connection->client()->getConnection()->getParameters()->host;
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }
}

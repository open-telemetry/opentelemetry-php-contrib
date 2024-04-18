<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\MongoDB;

use Closure;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

final class MongoDBInstrumentationSubscriber implements CommandSubscriber
{
    private CachedInstrumentation $instrumentation;
    /**
     * @var Closure(object):?string
     */
    private Closure $commandSerializer;

    /**
     * @param (callable(object):?string) $commandSerializer
     */
    public function __construct(CachedInstrumentation $instrumentation, callable $commandSerializer)
    {
        $this->instrumentation = $instrumentation;
        $this->commandSerializer = static function (object $command) use ($commandSerializer): ?string {
            try {
                return $commandSerializer($command);
            } catch (Throwable $exception) {
                return null;
            }
        };
    }

    public function commandStarted(CommandStartedEvent $event): void
    {
        $command = $event->getCommand();
        $collectionName = MongoDBCollectionExtractor::extract($command);
        $databaseName = $event->getDatabaseName();
        $commandName = $event->getCommandName();
        $server = $event->getServer();
        $info = $server->getInfo();
        $port = $server->getPort();
        $host = $server->getHost();
        $isSocket = str_starts_with($host, '/');
        /** @psalm-suppress RiskyTruthyFalsyComparison **/
        $scopedCommand = ($collectionName ? $collectionName . '.' : '') . $commandName;

        $builder = self::startSpan($this->instrumentation, 'MongoDB ' . $scopedCommand)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(TraceAttributes::DB_SYSTEM, 'mongodb')
            ->setAttribute(TraceAttributes::DB_NAME, $databaseName)
            ->setAttribute(TraceAttributes::DB_OPERATION, $commandName)
            ->setAttribute(TraceAttributes::SERVER_ADDRESS, $isSocket ? null : $host)
            ->setAttribute(TraceAttributes::SERVER_PORT, $isSocket ? null : $port)
            ->setAttribute(TraceAttributes::NETWORK_TRANSPORT, $isSocket ? 'unix' : 'tcp')
            ->setAttribute(TraceAttributes::DB_STATEMENT, ($this->commandSerializer)($command))
            ->setAttribute(TraceAttributes::DB_MONGODB_COLLECTION, $collectionName)
            ->setAttribute(MongoDBTraceAttributes::DB_MONGODB_MASTER, $info['ismaster'] ?? null)
            ->setAttribute(MongoDBTraceAttributes::DB_MONGODB_READ_ONLY, $info['readOnly'] ?? null)
            ->setAttribute(MongoDBTraceAttributes::DB_MONGODB_CONNECTION_ID, $info['connectionId'] ?? null)
            ->setAttribute(MongoDBTraceAttributes::DB_MONGODB_REQUEST_ID, $event->getRequestId())
            ->setAttribute(MongoDBTraceAttributes::DB_MONGODB_OPERATION_ID, $event->getOperationId())
            ->setAttribute(MongoDBTraceAttributes::DB_MONGODB_MAX_WIRE_VERSION, $info['maxWireVersion'] ?? null)
            ->setAttribute(MongoDBTraceAttributes::DB_MONGODB_MIN_WIRE_VERSION, $info['minWireVersion'] ?? null)
            ->setAttribute(MongoDBTraceAttributes::DB_MONGODB_MAX_BSON_OBJECT_SIZE_BYTES, $info['maxBsonObjectSize'] ?? null)
            ->setAttribute(MongoDBTraceAttributes::DB_MONGODB_MAX_MESSAGE_SIZE_BYTES, $info['maxMessageSizeBytes'] ?? null)
            ->setAttribute(MongoDBTraceAttributes::DB_MONGODB_MAX_WRITE_BATCH_SIZE, $info['maxWriteBatchSize'] ?? null);

        $parent = Context::getCurrent();
        $span = $builder->startSpan();
        Context::storage()->attach($span->storeInContext($parent));
    }

    public function commandSucceeded(CommandSucceededEvent $event): void
    {
        self::endSpan();
    }

    public function commandFailed(CommandFailedEvent $event): void
    {
        self::endSpan($event->getError());
    }

    /**
     * @param non-empty-string $name
     */
    private static function startSpan(CachedInstrumentation $instrumentation, string $name): SpanBuilderInterface
    {
        return $instrumentation->tracer()
            ->spanBuilder($name);
    }

    private static function endSpan(?Throwable $exception = null): void
    {
        $scope = Context::storage()->scope();
        if ($scope === null) {
            return;
        }

        $scope->detach();
        $span = Span::fromContext($scope->context());
        if ($exception) {
            $span->recordException($exception, [
                TraceAttributes::EXCEPTION_ESCAPED => true,
            ]);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }
}

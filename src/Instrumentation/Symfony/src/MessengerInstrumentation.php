<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Symfony\Propagation\EnvelopeContextPropagator;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Worker;

/**
 * The messenger instrumentation will create spans for message operations in Symfony's Messenger system.
 * It supports distributed tracing and provides rich metadata about message processing.
 */
final class MessengerInstrumentation
{
    const ATTRIBUTE_MESSAGING_SYSTEM = 'messaging.system';
    const ATTRIBUTE_MESSAGING_OPERATION = 'messaging.operation';
    const ATTRIBUTE_MESSAGING_DESTINATION = 'messaging.destination';
    const ATTRIBUTE_MESSAGING_MESSAGE_ID = 'messaging.message_id';
    const ATTRIBUTE_MESSAGING_MESSAGE = 'messaging.message';
    const ATTRIBUTE_MESSAGING_BUS = 'messaging.symfony.bus';
    const ATTRIBUTE_MESSAGING_HANDLER = 'messaging.symfony.handler';
    const ATTRIBUTE_MESSAGING_REDELIVERED_AT = 'messaging.symfony.redelivered_at';
    const ATTRIBUTE_MESSAGING_SENDER = 'messaging.symfony.sender';
    const ATTRIBUTE_MESSAGING_DELAY = 'messaging.symfony.delay';
    const ATTRIBUTE_MESSAGING_RETRY_COUNT = 'messaging.symfony.retry_count';
    const ATTRIBUTE_MESSAGING_STAMPS = 'messaging.symfony.stamps';

    // Constants used in tests
    const ATTRIBUTE_MESSENGER_BUS = self::ATTRIBUTE_MESSAGING_BUS;
    const ATTRIBUTE_MESSENGER_TRANSPORT = self::ATTRIBUTE_MESSAGING_DESTINATION;
    const ATTRIBUTE_MESSENGER_MESSAGE = self::ATTRIBUTE_MESSAGING_MESSAGE;

    /** @psalm-suppress PossiblyUnusedMethod */
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.symfony_messenger',
            null,
            'https://opentelemetry.io/schemas/1.30.0',
        );

        /**
         * MessageBusInterface dispatches messages to the handlers.
         */
        hook(
            MessageBusInterface::class,
            'dispatch',
            pre: static function (
                MessageBusInterface $bus,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                /** @var object|Envelope $message */
                $message = $params[0];
                $messageClass = \get_class($message);

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('DISPATCH %s', $messageClass))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_SYSTEM, 'symfony')
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_OPERATION, 'dispatch')
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_MESSAGE, $messageClass)
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_BUS, $class)
                ;

                if ($message instanceof Envelope) {
                    self::addMessageStampsToSpan($builder, $message);
                }

                $parent = Context::getCurrent();
                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);
                Context::storage()->attach($context);

                return $params;
            },
            post: static function (
                MessageBusInterface $bus,
                array $params,
                ?Envelope $result,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }

                $scope->detach();
                $span = Span::fromContext($scope->context());

                if (null !== $exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );

        /**
         * SenderInterface sends messages to a transport.
         */
        hook(
            SenderInterface::class,
            'send',
            pre: static function (
                SenderInterface $sender,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                /** @var Envelope $envelope */
                $envelope = $params[0];
                $messageClass = \get_class($envelope->getMessage());

                // Inject OpenTelemetry context into message envelope
                $propagator = Globals::propagator();
                $currentContext = Context::getCurrent();
                $headers = [];
                $propagator->inject($headers, EnvelopeContextPropagator::getInstance(), $currentContext);
                
                $envelope = EnvelopeContextPropagator::getInstance()->injectContextIntoEnvelope($envelope, $headers);

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('SEND %s', $messageClass))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_SYSTEM, 'symfony')
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_OPERATION, 'send')
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_MESSAGE, $messageClass)
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_DESTINATION, $class)
                ;

                self::addMessageStampsToSpan($builder, $envelope);

                $parent = Context::getCurrent();
                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);
                Context::storage()->attach($context);

                return [$envelope];
            },
            post: static function (
                SenderInterface $sender,
                array $params,
                ?Envelope $result,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }

                $scope->detach();
                $span = Span::fromContext($scope->context());

                if (null !== $exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );

        /**
         * Worker handles messages from a transport.
         */
        hook(
            Worker::class,
            'handleMessage',
            pre: static function (
                Worker $worker,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                /** @var Envelope $envelope */
                $envelope = $params[0];
                /** @var string|ReceiverInterface $transport */
                $transport = $params[1];
                $transportName = \is_object($transport) ? \get_class($transport) : $transport;

                // Extract OpenTelemetry context from message envelope
                $propagator = Globals::propagator();
                $extractedContext = EnvelopeContextPropagator::getInstance()->extractContextFromEnvelope($envelope);
                if ($extractedContext !== null) {
                    $propagator->extract($extractedContext, EnvelopeContextPropagator::getInstance());
                }

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('CONSUME %s', \get_class($envelope->getMessage())))
                    ->setSpanKind(SpanKind::KIND_CONSUMER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_SYSTEM, 'symfony')
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_OPERATION, 'receive')
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_DESTINATION, $transportName)
                ;

                self::addMessageStampsToSpan($builder, $envelope);

                $parent = Context::getCurrent();
                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);
                Context::storage()->attach($context);

                return $params;
            },
            post: static function (
                Worker $worker,
                array $params,
                ?Envelope $result,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }

                $scope->detach();
                $span = Span::fromContext($scope->context());

                if (null !== $exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );

        /**
         * HandleMessageMiddleware processes messages with handlers.
         */
        hook(
            'Symfony\Component\Messenger\Middleware\HandleMessageMiddleware',
            'handle',
            pre: static function (
                object $middleware,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                /** @var Envelope $envelope */
                $envelope = $params[0];
                $messageClass = \get_class($envelope->getMessage());

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('HANDLE %s', $messageClass))
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_SYSTEM, 'symfony')
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_OPERATION, 'process')
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_MESSAGE, $messageClass)
                ;

                self::addMessageStampsToSpan($builder, $envelope);

                $parent = Context::getCurrent();
                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);
                Context::storage()->attach($context);

                return $params;
            },
            post: static function (
                object $middleware,
                array $params,
                ?Envelope $result,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }

                $scope->detach();
                $span = Span::fromContext($scope->context());

                if (null !== $exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );
    }

    private static function addMessageStampsToSpan($builder, Envelope $envelope): void
    {
        $busStamp = $envelope->last(BusNameStamp::class);
        $consumedByWorkerStamp = $envelope->last(ConsumedByWorkerStamp::class);
        $delayStamp = $envelope->last(DelayStamp::class);
        $handledStamp = $envelope->last(HandledStamp::class);
        $receivedStamp = $envelope->last(ReceivedStamp::class);
        $redeliveryStamp = $envelope->last(RedeliveryStamp::class);
        $sentStamp = $envelope->last(SentStamp::class);
        $transportMessageIdStamp = $envelope->last(TransportMessageIdStamp::class);

        if ($busStamp) {
            $builder->setAttribute(self::ATTRIBUTE_MESSAGING_BUS, $busStamp->getBusName());
        }

        if ($handledStamp) {
            $builder->setAttribute(self::ATTRIBUTE_MESSAGING_HANDLER, $handledStamp->getHandlerName());
        }

        if ($redeliveryStamp) {
            $builder->setAttribute(
                self::ATTRIBUTE_MESSAGING_REDELIVERED_AT,
                $redeliveryStamp->getRedeliveredAt()->format('Y-m-d\TH:i:sP')
            );
            $builder->setAttribute(self::ATTRIBUTE_MESSAGING_RETRY_COUNT, $redeliveryStamp->getRetryCount());
        }

        if ($sentStamp) {
            $builder->setAttribute(self::ATTRIBUTE_MESSAGING_SENDER, $sentStamp->getSenderClass());
        }

        if ($delayStamp) {
            $builder->setAttribute(self::ATTRIBUTE_MESSAGING_DELAY, $delayStamp->getDelay());
        }

        if ($transportMessageIdStamp) {
            $builder->setAttribute(self::ATTRIBUTE_MESSAGING_MESSAGE_ID, $transportMessageIdStamp->getId());
        }

        // Add count of all stamps as a metric
        $stamps = [];
        foreach ($envelope->all() as $stampFqcn => $instances) {
            $stamps[$stampFqcn] = \count($instances);
        }
        if (!empty($stamps)) {
            $builder->setAttribute(self::ATTRIBUTE_MESSAGING_STAMPS, $stamps);
        }
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
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
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Worker;

// Add Amazon SQS stamp class if available
if (\class_exists('Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsReceivedStamp')) {
    class_alias(
        'Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsReceivedStamp',
        'OpenTelemetry\Contrib\Instrumentation\Symfony\AmazonSqsReceivedStamp'
    );
}

/**
 * The messenger instrumentation creates spans for message operations in Symfony's Messenger system.
 *
 * This implementation follows the OpenTelemetry messaging semantic conventions:
 * @see https://opentelemetry.io/docs/specs/semconv/messaging/
 *
 * Key features:
 * - Context propagation between producers and consumers
 * - Span naming following the "{operation} {destination}" convention
 * - Proper span kind assignment based on operation type
 * - Standard messaging attributes
 * - Support for distributed tracing across message boundaries
 */
final class MessengerInstrumentation
{
    // Standard messaging operation types as defined in the spec
    private const OPERATION_TYPE_CREATE = 'create';
    private const OPERATION_TYPE_SEND = 'send';
    private const OPERATION_TYPE_RECEIVE = 'receive';
    private const OPERATION_TYPE_PROCESS = 'process';

    // Symfony-specific operation types
    private const OPERATION_TYPE_MIDDLEWARE = 'middleware';

    // Attribute constants
    const ATTRIBUTE_MESSAGING_MESSAGE = 'messaging.message';
    const ATTRIBUTE_MESSAGING_BUS = 'messaging.symfony.bus';
    const ATTRIBUTE_MESSAGING_HANDLER = 'messaging.symfony.handler';
    const ATTRIBUTE_MESSAGING_REDELIVERED_AT = 'messaging.symfony.redelivered_at';
    const ATTRIBUTE_MESSAGING_SENDER = 'messaging.symfony.sender';
    const ATTRIBUTE_MESSAGING_DELAY = 'messaging.symfony.delay';
    const ATTRIBUTE_MESSAGING_RETRY_COUNT = 'messaging.symfony.retry_count';
    const ATTRIBUTE_MESSAGING_STAMPS = 'messaging.symfony.stamps';
    const ATTRIBUTE_MESSAGING_MIDDLEWARE = 'symfony.messenger.middleware';
    const ATTRIBUTE_MESSAGING_CONSUMED_BY_WORKER = 'messaging.symfony.consumed_by_worker';

    // Constants used in tests
    const ATTRIBUTE_MESSENGER_BUS = self::ATTRIBUTE_MESSAGING_BUS;
    const ATTRIBUTE_MESSENGER_MESSAGE = self::ATTRIBUTE_MESSAGING_MESSAGE;

    /**
     * Registers the instrumentation hooks for Symfony Messenger.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function register(): void
    {
        // Check if we should use the stable messaging conventions
        $useStableConventions = self::shouldUseStableConventions();
        $emitDuplicateConventions = self::shouldEmitDuplicateConventions();

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

                // Get destination name if available
                $destinationName = $message instanceof Envelope
                    ? self::getDestinationName($message)
                    : $class;

                // Create span with proper naming convention
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('%s %s', self::OPERATION_TYPE_CREATE, $destinationName))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, 'symfony')
                    ->setAttribute(TraceAttributes::MESSAGING_OPERATION_TYPE, self::OPERATION_TYPE_CREATE)
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_MESSAGE, $messageClass)
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_BUS, $class)
                    ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_NAME, $destinationName)
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

                // Get destination name
                $destinationName = self::getDestinationName($envelope) ?: $class;

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('%s %s', self::OPERATION_TYPE_SEND, $destinationName))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, 'symfony')
                    ->setAttribute(TraceAttributes::MESSAGING_OPERATION_TYPE, self::OPERATION_TYPE_SEND)
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_MESSAGE, $messageClass)
                    ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_NAME, $destinationName)
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

                // Use ReceivedStamp transport name if available
                $receivedStamp = $envelope->last(ReceivedStamp::class);
                if ($receivedStamp) {
                    $transportName = $receivedStamp->getTransportName();
                }

                // Extract OpenTelemetry context from message envelope
                $propagator = Globals::propagator();
                $extractedContext = EnvelopeContextPropagator::getInstance()->extractContextFromEnvelope($envelope);
                if ($extractedContext !== null) {
                    $propagator->extract($extractedContext, EnvelopeContextPropagator::getInstance());
                }

                $messageClass = \get_class($envelope->getMessage());

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('%s %s', self::OPERATION_TYPE_RECEIVE, $transportName))
                    ->setSpanKind(SpanKind::KIND_CONSUMER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, 'symfony')
                    ->setAttribute(TraceAttributes::MESSAGING_OPERATION_TYPE, self::OPERATION_TYPE_RECEIVE)
                    ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_NAME, $transportName)
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

                // Get destination name
                $destinationName = self::getDestinationName($envelope) ?: $messageClass;

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('%s %s', self::OPERATION_TYPE_PROCESS, $destinationName))
                    ->setSpanKind(SpanKind::KIND_CONSUMER) // Changed from INTERNAL to CONSUMER per spec
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, 'symfony')
                    ->setAttribute(TraceAttributes::MESSAGING_OPERATION_TYPE, self::OPERATION_TYPE_PROCESS)
                    ->setAttribute(self::ATTRIBUTE_MESSAGING_MESSAGE, $messageClass)
                    ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_NAME, $destinationName)
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

        // Add instrumentation for individual handlers in Symfony 6.2+
        if (method_exists('Symfony\Component\Messenger\Middleware\HandleMessageMiddleware', 'callHandler')) {
            hook(
                'Symfony\Component\Messenger\Middleware\HandleMessageMiddleware',
                'callHandler',
                pre: static function (
                    object $middleware,
                    array $params,
                    string $class,
                    string $function,
                    ?string $filename,
                    ?int $lineno,
                ) use ($instrumentation): array {
                    $handler = $params[0];
                    $message = $params[1];
                    $handlerClass = \get_class($handler);
                    $messageClass = \get_class($message);

                    // For handler-specific spans, use a custom destination format
                    $destinationName = sprintf('%s::%s', $handlerClass, $messageClass);

                    /** @psalm-suppress ArgumentTypeCoercion */
                    $builder = $instrumentation
                        ->tracer()
                        ->spanBuilder(\sprintf('%s %s', self::OPERATION_TYPE_PROCESS, $destinationName))
                        ->setSpanKind(SpanKind::KIND_CONSUMER) // Changed from INTERNAL to CONSUMER per spec
                        ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                        ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                        ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, 'symfony')
                        ->setAttribute(TraceAttributes::MESSAGING_OPERATION_TYPE, self::OPERATION_TYPE_PROCESS)
                        ->setAttribute(self::ATTRIBUTE_MESSAGING_MESSAGE, $messageClass)
                        ->setAttribute(self::ATTRIBUTE_MESSAGING_HANDLER, $handlerClass)
                        ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_NAME, $destinationName)
                    ;

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
                    $result,
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

        if (getenv('OTEL_PHP_MESSENGER_INSTRUMENT_MIDDLEWARES')) {
            /**
             * Instrument all middlewares
             */
            hook(
                'Symfony\Component\Messenger\Middleware\MiddlewareInterface',
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
                    $middlewareClass = \get_class($middleware);

                    // For middleware spans, use a custom destination format
                    $destinationName = sprintf('%s::%s', $middlewareClass, $messageClass);

                    /** @psalm-suppress ArgumentTypeCoercion */
                    $builder = $instrumentation
                        ->tracer()
                        ->spanBuilder(\sprintf('%s %s', self::OPERATION_TYPE_MIDDLEWARE, $destinationName))
                        ->setSpanKind(SpanKind::KIND_INTERNAL) // Keep as INTERNAL since middleware is not a standard messaging operation
                        ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                        ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                        ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, 'symfony')
                        ->setAttribute(TraceAttributes::MESSAGING_OPERATION_TYPE, self::OPERATION_TYPE_MIDDLEWARE)
                        ->setAttribute(self::ATTRIBUTE_MESSAGING_MESSAGE, $messageClass)
                        ->setAttribute(self::ATTRIBUTE_MESSAGING_MIDDLEWARE, $middlewareClass)
                        ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_NAME, $destinationName)
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
    }

    /**
     * Determines if stable messaging conventions should be used.
     */
    private static function shouldUseStableConventions(): bool
    {
        $optIn = getenv('OTEL_SEMCONV_STABILITY_OPT_IN');
        if (!$optIn) {
            return false;
        }

        $values = explode(',', $optIn);

        return in_array('messaging', $values) || in_array('messaging/dup', $values);
    }

    /**
     * Determines if both old and new conventions should be emitted.
     */
    private static function shouldEmitDuplicateConventions(): bool
    {
        $optIn = getenv('OTEL_SEMCONV_STABILITY_OPT_IN');
        if (!$optIn) {
            return false;
        }

        $values = explode(',', $optIn);

        return in_array('messaging/dup', $values);
    }

    /**
     * Gets the destination name from an envelope.
     */
    private static function getDestinationName(Envelope $envelope): ?string
    {
        $sentStamp = $envelope->last(SentStamp::class);
        $receivedStamp = $envelope->last(ReceivedStamp::class);

        if ($sentStamp && $sentStamp->getSenderAlias()) {
            return $sentStamp->getSenderAlias();
        }

        if ($receivedStamp) {
            return $receivedStamp->getTransportName();
        }

        return null;
    }

    /**
     * Adds message stamps as span attributes.
     */
    private static function addMessageStampsToSpan(SpanBuilderInterface $builder, Envelope $envelope): void
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

        if ($consumedByWorkerStamp) {
            $builder->setAttribute(self::ATTRIBUTE_MESSAGING_CONSUMED_BY_WORKER, true);
        }

        if ($handledStamp) {
            $builder->setAttribute(self::ATTRIBUTE_MESSAGING_HANDLER, $handledStamp->getHandlerName());
        }

        if ($redeliveryStamp) {
            $builder->setAttribute(self::ATTRIBUTE_MESSAGING_REDELIVERED_AT, $redeliveryStamp->getRedeliveredAt()->format('Y-m-d\TH:i:sP'));
            $builder->setAttribute(self::ATTRIBUTE_MESSAGING_RETRY_COUNT, $redeliveryStamp->getRetryCount());
        }

        if ($sentStamp) {
            $builder->setAttribute(self::ATTRIBUTE_MESSAGING_SENDER, $sentStamp->getSenderClass());
            $builder->setAttribute(TraceAttributes::MESSAGING_DESTINATION_NAME, $sentStamp->getSenderAlias());
        } elseif ($receivedStamp) {
            $builder->setAttribute(TraceAttributes::MESSAGING_DESTINATION_NAME, $receivedStamp->getTransportName());
        }

        if ($transportMessageIdStamp) {
            $builder->setAttribute(TraceAttributes::MESSAGING_MESSAGE_ID, $transportMessageIdStamp->getId());
        }

        if ($delayStamp) {
            $builder->setAttribute(self::ATTRIBUTE_MESSAGING_DELAY, $delayStamp->getDelay());
        }

        // Add all stamps count
        $stamps = [];
        foreach ($envelope->all() as $stampFqcn => $instances) {
            $stamps[$stampFqcn] = \count($instances);
        }
        if (!empty($stamps)) {
            $builder->setAttribute(self::ATTRIBUTE_MESSAGING_STAMPS, \json_encode($stamps));
        }

        // Support for Amazon SQS
        if (\class_exists('Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsReceivedStamp')) {
            /** @var \Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsReceivedStamp|null $amazonSqsReceivedStamp */
            $amazonSqsReceivedStamp = $envelope->last('Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsReceivedStamp');
            if ($amazonSqsReceivedStamp && !$transportMessageIdStamp && method_exists($amazonSqsReceivedStamp, 'getId')) {
                $builder->setAttribute(TraceAttributes::MESSAGING_MESSAGE_ID, $amazonSqsReceivedStamp->getId());
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * The messenger instrumentation will create an internal span for each message dispatched.
 *
 * It is currently not designed to facilitate trace context propagation.
 * This should be done at the transport level.
 *
 * An exception to this will be simple transports like the Doctrine and InMemory transports.
 *
 * Caution: MessageBuses can be nested, so we might wand to add
 * a message stamp to keep track of the parent span.
 */
final class MessengerInstrumentation
{
    const ATTRIBUTE_MESSENGER_BUS = 'symfony.messenger.bus';
    const ATTRIBUTE_MESSENGER_MESSAGE = 'symfony.messenger.message';
    const ATTRIBUTE_MESSENGER_TRANSPORT = 'symfony.messenger.transport';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.symfony_messenger');


         // Instrument MessageBusInterface (message dispatching)
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

                // Instrument dispatch as a "send" operation with SpanKind::KIND_PRODUCER
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('publish %s', $messageClass))
                    ->setSpanKind(SpanKind::KIND_PRODUCER) // Set KIND_PRODUCER for dispatch
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                    ->setAttribute(self::ATTRIBUTE_MESSENGER_BUS, $class)
                    ->setAttribute(self::ATTRIBUTE_MESSENGER_MESSAGE, $messageClass);

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
                    $span->recordException($exception, [
                        TraceAttributes::EXCEPTION_ESCAPED => true,
                    ]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );

        // Instrument SenderInterface (sending messages to transport)
        hook(
            SenderInterface::class,
            'send',
            pre: static function (
                SenderInterface $bus,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                /** @var Envelope $envelope */
                $envelope = $params[0];
                $messageClass = \get_class($envelope->getMessage());

                 // Instrument sending as a "send" operation with SpanKind::KIND_PRODUCER
                $builder = $instrumentation
<<<<<<< HEAD
                 ->tracer()
                 ->spanBuilder(\sprintf('send %s', $messageClass))
                 ->setSpanKind(SpanKind::KIND_PRODUCER) // Set KIND_PRODUCER for sending
                 ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                 ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                 ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                 ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                 ->setAttribute(self::ATTRIBUTE_MESSENGER_TRANSPORT, $class)
                 ->setAttribute(self::ATTRIBUTE_MESSENGER_MESSAGE, $messageClass);
=======
                    ->tracer()
                    ->spanBuilder(\sprintf('SEND %s', $messageClass))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)

                    ->setAttribute(self::ATTRIBUTE_MESSENGER_TRANSPORT, $class)
                    ->setAttribute(self::ATTRIBUTE_MESSENGER_MESSAGE, $messageClass)
                ;
>>>>>>> 78a04cebaeba48d60a00dc1c48653695b926299d

                $parent = Context::getCurrent();
                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);

                Context::storage()->attach($context);

                return $params;
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
                    $span->recordException($exception, [
                        TraceAttributes::EXCEPTION_ESCAPED => true,
                    ]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );

         // Instrument the receiving of messages (consumer-side)
         hook(
            SenderInterface::class,
            'receive',
            pre: static function (
                SenderInterface $bus,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                /** @var Envelope $envelope */
                $envelope = $params[0];
                $messageClass = \get_class($envelope->getMessage());

                // Instrument receiving as a "consume" operation with SpanKind::KIND_CONSUMER
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('consume %s', $messageClass))
                    ->setSpanKind(SpanKind::KIND_CONSUMER) // Set KIND_CONSUMER for receiving
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                    ->setAttribute(self::ATTRIBUTE_MESSENGER_TRANSPORT, $class)
                    ->setAttribute(self::ATTRIBUTE_MESSENGER_MESSAGE, $messageClass);

                $parent = Context::getCurrent();
                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);
                Context::storage()->attach($context);

                return $params;
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
                    $span->recordException($exception, [
                        TraceAttributes::EXCEPTION_ESCAPED => true,
                    ]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );
    }
}

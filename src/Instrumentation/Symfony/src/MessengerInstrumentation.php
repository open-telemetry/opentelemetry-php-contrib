<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Version;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * The messenger instrumentation will create an internal span for each message dispatched.
 *
 * It is currently not designed to facilitate trace context propagation.
 * This should be done at the transport level.
 *
 * An exception to this will be simple transports like the Doctrine and InMemory transports.
 *
 * A decorator handing a message over to the object it decorates creates no span of its own: the worker's
 * RoutableMessageBus and the bus it routes to, or a transport and its sender (AmqpTransport and AmqpSender),
 * record a single span.
 */
final class MessengerInstrumentation
{
    const ATTRIBUTE_MESSENGER_BUS = 'symfony.messenger.bus';
    const ATTRIBUTE_MESSENGER_MESSAGE = 'symfony.messenger.message';
    const ATTRIBUTE_MESSENGER_TRANSPORT = 'symfony.messenger.transport';

    private static function messageKey(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey('symfony-messenger.message');
    }

    private static function forwardedKey(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey('symfony-messenger.forwarded');
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.symfony_messenger',
            null,
            Version::VERSION_1_38_0->url(),
        );

        /**
         * MessageBusInterface dispatches messages to the handlers.
         *
         * @psalm-suppress UnusedFunctionCall
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

                // A message dispatched by a worker after being pulled from a transport
                // carries a ReceivedStamp. That is the consumer side of messaging, so it
                // must be a CONSUMER span; a plain dispatch is the producer side. For an
                // Envelope, report the wrapped message class rather than Envelope. See #1314.
                $isReceiving = false;
                $messageObject = $message;
                if ($message instanceof Envelope) {
                    $messageObject = $message->getMessage();
                    $isReceiving = $message->last(ReceivedStamp::class) !== null;
                }
                $messageClass = \get_class($messageObject);
                $operation = $isReceiving ? 'CONSUME' : 'DISPATCH';

                $parent = Context::getCurrent();
                if (self::isHandedOver($parent, $operation, $messageObject)) {
                    Context::storage()->attach($parent->with(self::forwardedKey(), true));

                    return $params;
                }

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('%s %s', $operation, $messageClass))
                    ->setSpanKind($isReceiving ? SpanKind::KIND_CONSUMER : SpanKind::KIND_PRODUCER)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)

                    ->setAttribute(self::ATTRIBUTE_MESSENGER_BUS, $class)
                    ->setAttribute(self::ATTRIBUTE_MESSENGER_MESSAGE, $messageClass)
                ;

                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                Context::storage()->attach(self::ownerContext($span->storeInContext($parent), $operation, $messageObject));

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

                if (true === $scope->context()->get(self::forwardedKey())) {
                    return;
                }

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
         *
         * @psalm-suppress UnusedFunctionCall
        */
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
                $messageObject = $envelope->getMessage();
                $messageClass = \get_class($messageObject);

                $parent = Context::getCurrent();
                if (self::isHandedOver($parent, 'SEND', $messageObject)) {
                    Context::storage()->attach($parent->with(self::forwardedKey(), true));

                    return $params;
                }

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('SEND %s', $messageClass))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)

                    ->setAttribute(self::ATTRIBUTE_MESSENGER_TRANSPORT, $class)
                    ->setAttribute(self::ATTRIBUTE_MESSENGER_MESSAGE, $messageClass)
                ;

                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                Context::storage()->attach(self::ownerContext($span->storeInContext($parent), 'SEND', $messageObject));

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

                if (true === $scope->context()->get(self::forwardedKey())) {
                    return;
                }

                $span = Span::fromContext($scope->context());

                if (null !== $exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );
    }

    /**
     * Whether the closest Messenger span records the same operation on the same message: the caller is a decorator
     * handing it over to the object it decorates.
     */
    private static function isHandedOver(ContextInterface $context, string $operation, object $message): bool
    {
        return $context->get(self::messageKey()) === [$operation, $message];
    }

    private static function ownerContext(ContextInterface $context, string $operation, object $message): ContextInterface
    {
        return $context
            ->with(self::messageKey(), [$operation, $message])
            ->with(self::forwardedKey(), false);
    }
}

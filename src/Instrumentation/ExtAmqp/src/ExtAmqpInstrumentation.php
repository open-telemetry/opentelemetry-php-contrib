<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\ExtAmqp;

use AMQPExchange;
use AMQPQueue;
use Composer\InstalledVersions;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;
use Throwable;

/**
 * This uses SemConv 1.24, until messaging SemConv becomes stable.
 * @see https://opentelemetry.io/docs/specs/semconv/messaging/rabbitmq/
 * @psalm-suppress UnusedClass
 */
final class ExtAmqpInstrumentation
{
    public const NAME = 'ext_amqp';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.ext_amqp',
            InstalledVersions::getVersion('open-telemetry/opentelemetry-auto-ext-amqp'),
            'https://opentelemetry.io/schemas/1.38.0',
        );

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            AMQPExchange::class,
            'publish',
            pre: static function (
                AMQPExchange $exchange,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                $routingKey = $params[1];

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('%s%s', $exchange->getName() != '' ? $exchange->getName() . ' ': '', $routingKey) . ' publish')
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    // code
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    // messaging
                    ->setAttribute(MessagingIncubatingAttributes::MESSAGING_SYSTEM, MessagingIncubatingAttributes::MESSAGING_SYSTEM_VALUE_RABBITMQ)
                    ->setAttribute(MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE, MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_SEND)

                    ->setAttribute(MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME, $routingKey)

                    ->setAttribute(MessagingIncubatingAttributes::MESSAGING_RABBITMQ_DESTINATION_ROUTING_KEY, $routingKey)

                    // network
                    ->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_NAME, 'amqp')
                    ->setAttribute(NetworkAttributes::NETWORK_TRANSPORT, NetworkAttributes::NETWORK_TRANSPORT_VALUE_TCP)

                    ->setAttribute(NetworkAttributes::NETWORK_PEER_ADDRESS, $exchange->getConnection()->getHost())
                    ->setAttribute(NetworkAttributes::NETWORK_PEER_PORT, $exchange->getConnection()->getPort())
                ;

                $parent = Context::getCurrent();

                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);

                /**
                 * Inject correlation context into message headers. This will only work if the
                 * method was called with the fourth parameter being supplied, as we can currently not
                 * expand the parameter list in native code.
                 *
                 * @see https://github.com/open-telemetry/opentelemetry-php-instrumentation/issues/68
                 */
                if (4 >= sizeof($params) && array_key_exists(3, $params)) {
                    $attributes = $params[3];

                    if (!array_key_exists('headers', $attributes)) {
                        $attributes['headers'] = [];
                    }

                    $propagator = Globals::propagator();
                    $propagator->inject($attributes['headers'], ArrayAccessGetterSetter::getInstance(), $context);

                    $params[3] = $attributes;
                }

                Context::storage()->attach($context);

                return $params;
            },
            post: static function (
                AMQPExchange $exchange,
                array $params,
                ?bool $success,
                ?Throwable $exception,
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());

                if ($success === false) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                if ($exception !== null) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                $span->end();
            }
        );

        self::createInteractionWithQueueSpan($instrumentation, AMQPQueue::class, 'ack');
        self::createInteractionWithQueueSpan($instrumentation, AMQPQueue::class, 'nack');
        self::createInteractionWithQueueSpan($instrumentation, AMQPQueue::class, 'reject');
    }

    protected static function createInteractionWithQueueSpan(CachedInstrumentation $instrumentation, $class, string $method)
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            $class,
            $method,
            pre: static function (
                AMQPQueue $queue,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation, $method): array {
                $queueName = $queue->getName();

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder($queueName . ' ' . $method)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    // code
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    // messaging
                    ->setAttribute(MessagingIncubatingAttributes::MESSAGING_SYSTEM, MessagingIncubatingAttributes::MESSAGING_SYSTEM_VALUE_RABBITMQ)
                    ->setAttribute(MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE, MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_SETTLE)
                    ->setAttribute(MessagingIncubatingAttributes::MESSAGING_OPERATION_NAME, $method)
                    ->setAttribute(MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME, $queueName)

                    ->setAttribute(MessagingIncubatingAttributes::MESSAGING_RABBITMQ_DESTINATION_ROUTING_KEY, $queueName)

                    ->setAttribute(MessagingIncubatingAttributes::MESSAGING_CLIENT_ID, $queue->getConsumerTag())

                    ->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_NAME, 'amqp')
                    ->setAttribute(NetworkAttributes::NETWORK_TRANSPORT, NetworkAttributes::NETWORK_TRANSPORT_VALUE_TCP)

                    ->setAttribute(NetworkAttributes::NETWORK_PEER_ADDRESS, $queue->getChannel()->getConnection()->getHost())
                    ->setAttribute(NetworkAttributes::NETWORK_PEER_PORT, $queue->getChannel()->getConnection()->getPort())
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
                AMQPQueue $queue,
                array $params,
                ?bool $success,
                ?Throwable $exception,
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());

                if ($success === false) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                if ($exception !== null) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                $span->end();
            }
        );
    }
}

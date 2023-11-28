<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\ExtAmqp;

use AMQPExchange;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use PhpAmqpLib\Channel\AMQPChannel;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class ExtAmqpInstrumentation
{
    public const NAME = 'ext_amqp';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.ext_amqp');

        hook(
            AMQPExchange::class,
            'publish',
            pre: static function (
                AMQPExchange $channel,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {

                $routingKey = $params[1];
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder($routingKey. ' publish')
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    // code
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                    // messaging
                    ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, 'rabbitmq')
                    ->setAttribute(TraceAttributes::MESSAGING_OPERATION, 'publish')
                    ->setAttribute(TraceAttributes::MESSAGING_OPERATION, 'publish')

                    ->setAttribute(TraceAttributes::MESSAGING_DESTINATION, $routingKey)
                    ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_NAME, $routingKey)

                    ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_KIND, 'queue')

                    ->setAttribute(TraceAttributes::MESSAGING_RABBITMQ_ROUTING_KEY, $routingKey)
                    ->setAttribute(TraceAttributes::MESSAGING_RABBITMQ_DESTINATION_ROUTING_KEY, $routingKey)
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
                AMQPExchange $channel,
                array $params,
                ?bool $response,
                ?Throwable $exception,
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());

                if ($exception !== null) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                $span->end();
            }
        );
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\ExtAmqp;

use AMQPExchange;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

final class ExtAmqpInstrumentation
{
    public const NAME = 'ext_amqp';

    public static function register(): void
    {
        if (!extension_loaded('amqp')) {
            return;
        }

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

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder($routingKey . ' publish')
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    // code
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                    // messaging
                    ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, 'rabbitmq')
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
    }
}
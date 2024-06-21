<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr14;

use Composer\InstalledVersions;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\EventDispatcher\EventDispatcherInterface;

use Throwable;

/**
 * @psalm-suppress ArgumentTypeCoercion
 */
class Psr14Instrumentation
{
    public const NAME = 'psr14';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.psr14',
            InstalledVersions::getVersion('open-telemetry/opentelemetry-auto-psr14'),
            'https://opentelemetry.io/schemas/1.24.0'
        );

        /**
         * Create a span for each PSR-14 event that is dispatched.
         */
        hook(
            EventDispatcherInterface::class,
            'dispatch',
            pre: static function (EventDispatcherInterface $dispatcher, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $event = is_object($params[0]) ? $params[0] : null;
                $builder = $instrumentation->tracer()
                   ->spanBuilder(sprintf('event %s', $event ? $event::class : 'unknown'))
                   ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                   ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                   ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                   ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

                if ($event) {
                    $builder->setAttribute('psr14.event.name', $event::class);
                }

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (EventDispatcherInterface $dispatcher, array $params, $return, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $scope->detach();
                $span = Span::fromContext($scope->context());

                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );
    }
}

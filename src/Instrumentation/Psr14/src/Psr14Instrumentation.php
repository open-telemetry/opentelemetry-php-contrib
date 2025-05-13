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
use OpenTelemetry\SemConv\Version;
use Psr\EventDispatcher\EventDispatcherInterface;

use Throwable;

/**
 * @psalm-suppress ArgumentTypeCoercion
 * @psalm-suppress UnusedClass
 */
class Psr14Instrumentation
{
    public const NAME = 'psr14';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.psr14',
            InstalledVersions::getVersion('open-telemetry/opentelemetry-auto-psr14'),
            Version::VERSION_1_32_0->url(),
        );

        /**
         * Create a span for each PSR-14 event that is dispatched.
         * @psalm-suppress UnusedFunctionCall
         */
        hook(
            EventDispatcherInterface::class,
            'dispatch',
            pre: static function (EventDispatcherInterface $dispatcher, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $event = is_object($params[0]) ? $params[0] : null;
                $builder = $instrumentation->tracer()
                   ->spanBuilder(sprintf('event %s', $event ? $event::class : 'unknown'))
                   ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                   ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                   ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);

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
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );
    }
}

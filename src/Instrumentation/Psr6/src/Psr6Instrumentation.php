<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr6;

use Composer\InstalledVersions;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

/**
 * @psalm-suppress ArgumentTypeCoercion
 */
class Psr6Instrumentation
{
    public const NAME = 'psr6';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.psr6',
            InstalledVersions::getVersion('open-telemetry/opentelemetry-auto-psr6')
        );

        $pre = static function (CacheInterface $cacheItem, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
            $span = self::makeSpanBuilder($instrumentation, $function, $class, $filename, $lineno)
                    ->startSpan();

            Context::storage()->attach($span->storeInContext(Context::getCurrent()));
        };
        $post = static function (CacheInterface $cacheItem, array $params, $return, ?Throwable $exception) {
            self::end($exception);
        };

        foreach (['get', 'set', 'delete', 'clear', 'getMultiple', 'setMultiple', 'deleteMultiple', 'has'] as $f) {
            hook(class: CacheInterface::class, function: $f, pre: $pre, post: $post);
        }
    }

    private static function makeSpanBuilder(
        CachedInstrumentation $instrumentation,
        string $function,
        string $class,
        ?string $filename,
        ?int $lineno
    ): SpanBuilderInterface {
        return $instrumentation->tracer()
            ->spanBuilder($function)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
            ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
            ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
            ->setAttribute(TraceAttributes::DB_SYSTEM, 'psr6')
            ->setAttribute(TraceAttributes::DB_OPERATION, $function);
    }

    private static function end(?Throwable $exception): void
    {
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
}
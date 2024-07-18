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
use Psr\Cache\CacheItemInterface;
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
            InstalledVersions::getVersion('open-telemetry/opentelemetry-auto-psr6'),
            'https://opentelemetry.io/schemas/1.24.0',
        );

        $pre = static function (CacheItemPoolInterface $pool, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
            $builder = self::makeSpanBuilder($instrumentation, $function, $function, $class, $filename, $lineno);

            if (isset($params[0]) && is_string($params[0])) {
                $builder->setAttribute('cache.key', $params[0]);
            }

            if (isset($params[0]) && is_array($params[0])) {
                $keys = (array_values($params[0]) !== $params[0]) ? array_keys($params[0]) : $params[0];
                $builder->setAttribute('cache.keys', implode(',', $keys));
            }

            if (isset($params[0]) && ($params[0] instanceof CacheItemInterface)) {
                $builder->setAttribute('cache.key', $params[0]->getKey());
            }

            $parent = Context::getCurrent();
            $span = $builder->startSpan();

            Context::storage()->attach($span->storeInContext($parent));
        };
        $post = static function (CacheItemPoolInterface $pool, array $params, $return, ?Throwable $exception) {
            self::end($exception);
        };

        foreach (['getItem', 'getItems', 'hasItem', 'clear', 'deleteItem', 'deleteItems', 'save', 'saveDeferred', 'commit'] as $f) {
            hook(class: CacheItemPoolInterface::class, function: $f, pre: $pre, post: $post);
        }
    }

    private static function makeSpanBuilder(
        CachedInstrumentation $instrumentation,
        string $name,
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
            ->setAttribute('cache.operation', $name);
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

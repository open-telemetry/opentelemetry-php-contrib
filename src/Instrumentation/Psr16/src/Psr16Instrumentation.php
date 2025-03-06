<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr16;

use Composer\InstalledVersions;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * @psalm-suppress ArgumentTypeCoercion
 */
class Psr16Instrumentation
{
    public const NAME = 'psr16';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.psr16',
            InstalledVersions::getVersion('open-telemetry/opentelemetry-auto-psr16'),
            'https://opentelemetry.io/schemas/1.30.0',
        );

        $pre = static function (CacheInterface $cacheItem, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
            $builder = self::makeSpanBuilder($instrumentation, $function, $function, $class, $filename, $lineno);

            if (isset($params[0]) && is_string($params[0])) {
                $builder->setAttribute('cache.key', $params[0]);
            }

            if (isset($params[0]) && is_array($params[0])) {
                $keys = (array_values($params[0]) !== $params[0]) ? array_keys($params[0]) : $params[0];
                $builder->setAttribute('cache.keys', implode(',', $keys));
            }

            $parent = Context::getCurrent();
            $span = $builder->startSpan();

            Context::storage()->attach($span->storeInContext($parent));
        };
        $post = static function (CacheInterface $cacheItem, array $params, $return, ?Throwable $exception) {
            self::end($exception);
        };

        foreach (['get', 'set', 'delete', 'clear', 'getMultiple', 'setMultiple', 'deleteMultiple', 'has'] as $f) {
            /** @psalm-suppress UnusedFunctionCall */
            hook(class: CacheInterface::class, function: $f, pre: $pre, post: $post);
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
            ->spanBuilder($name)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
            ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
            ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
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
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }
}

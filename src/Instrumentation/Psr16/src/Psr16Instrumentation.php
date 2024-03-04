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
            InstalledVersions::getVersion('open-telemetry/opentelemetry-auto-psr16')
        );

        hook(
            CacheInterface::class,
            'get',
            pre: static function (CacheInterface $cacheItem, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = self::makeSpanBuilder($instrumentation, $function, $class, $filename, $lineno)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (CacheInterface $cacheItem, array $params, $return, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            CacheInterface::class,
            'set',
            pre: static function (CacheInterface $cacheItem, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = self::makeSpanBuilder($instrumentation, $function, $class, $filename, $lineno)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (CacheInterface $cacheItem, array $params, $return, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            CacheInterface::class,
            'delete',
            pre: static function (CacheInterface $cacheItem, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = self::makeSpanBuilder($instrumentation, $function, $class, $filename, $lineno)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (CacheInterface $cacheItem, array $params, $return, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            CacheInterface::class,
            'clear',
            pre: static function (CacheInterface $cacheItem, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = self::makeSpanBuilder($instrumentation, $function, $class, $filename, $lineno)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (CacheInterface $cacheItem, array $params, $return, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            CacheInterface::class,
            'getMultiple',
            pre: static function (CacheInterface $cacheItem, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = self::makeSpanBuilder($instrumentation, $function, $class, $filename, $lineno)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (CacheInterface $cacheItem, array $params, $return, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            CacheInterface::class,
            'setMultiple',
            pre: static function (CacheInterface $cacheItem, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = self::makeSpanBuilder($instrumentation, $function, $class, $filename, $lineno)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (CacheInterface $cacheItem, array $params, $return, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            CacheInterface::class,
            'deleteMultiple',
            pre: static function (CacheInterface $cacheItem, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = self::makeSpanBuilder($instrumentation, $function, $class, $filename, $lineno)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (CacheInterface $cacheItem, array $params, $return, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            CacheInterface::class,
            'has',
            pre: static function (CacheInterface $cacheItem, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = self::makeSpanBuilder($instrumentation, $function, $class, $filename, $lineno)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (CacheInterface $cacheItem, array $params, $return, ?Throwable $exception) {
                self::end($exception);
            }
        );
    }

    private static function makeSpanBuilder(
        CachedInstrumentation $instrumentation,
        string $function,
        string $class,
        ?string $filename,
        ?int $lineno
    ): SpanBuilderInterface
    {
        return $instrumentation->tracer()
            ->spanBuilder(sprintf('%s::%s', $class, $function))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
            ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
            ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);
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

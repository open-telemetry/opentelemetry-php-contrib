<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\IO;

use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class IOInstrumentation
{
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.io');

        self::_hook($instrumentation, null, 'fopen', 'fopen');
        self::_hook($instrumentation, null, 'fwrite', 'fwrite');
        self::_hook($instrumentation, null, 'fread', 'fread');
        self::_hook($instrumentation, null, 'file_get_contents', 'file_get_contents');
        self::_hook($instrumentation, null, 'file_put_contents', 'file_put_contents');

        self::_hook($instrumentation, null, 'curl_exec', 'curl_exec');
    }

    /**
     * Simple generic hook function which starts and ends a minimal span
     */
    private static function _hook(CachedInstrumentation $instrumentation, ?string $class, string $function, string $name): void
    {
        hook(
            class: $class,
            function: $function,
            pre: static function ($object, ?array $params, ?string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $name) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, $name, $function, $filename, $lineno);
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {
                self::end($exception);
            }
        );
    }

    private static function makeBuilder(
        CachedInstrumentation $instrumentation,
        string $name,
        string $function,
        ?string $filename,
        ?int $lineno
    ): SpanBuilderInterface {
        /** @psalm-suppress ArgumentTypeCoercion */
        return $instrumentation->tracer()
                    ->spanBuilder($name)
                    ->setAttribute('code.function', $function)
                    ->setAttribute('code.filepath', $filename)
                    ->setAttribute('code.lineno', $lineno);
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

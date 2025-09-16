<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\IO;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use Throwable;

/** @psalm-suppress UnusedClass */
class IOInstrumentation
{
    public const NAME = 'io';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.io',
            null,
            Version::VERSION_1_32_0->url(),
        );

        self::_hook($instrumentation, null, 'fopen', 'fopen');
        self::_hook($instrumentation, null, 'fwrite', 'fwrite');
        self::_hook($instrumentation, null, 'fread', 'fread');
        self::_hook($instrumentation, null, 'file_get_contents', 'file_get_contents');
        self::_hook($instrumentation, null, 'file_put_contents', 'file_put_contents');

        // Output buffer functions
        self::_hook($instrumentation, null, 'ob_start', 'ob_start');
        self::_hook($instrumentation, null, 'ob_clean', 'ob_clean');
        self::_hook($instrumentation, null, 'ob_flush', 'ob_flush');
        self::_hook($instrumentation, null, 'flush', 'flush');
  
    }

    /**
     * Simple generic hook function which starts and ends a minimal span
     * @psalm-suppress UnusedFunctionCall
     */
    private static function _hook(CachedInstrumentation $instrumentation, ?string $class, string $function, string $name): void
    {
        hook(
            class: $class,
            function: $function,
            pre: static function ($object, ?array $params, ?string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $name) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, $name, $function, $filename, $lineno);
                self::addParams($builder, $function, $params);
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
            ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
            ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);
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

    private static function addParams(SpanBuilderInterface $builder, string $function, ?array $params): void
    {
        if ($params === null) {
            return;
        }
        switch ($function) {
            case 'fopen':
                $builder->setAttribute('code.params.filename', $params[0])
                    ->setAttribute('code.params.mode', $params[1]);

                break;
            case 'file_get_contents':
            case 'file_put_contents':
                $builder->setAttribute('code.params.filename', $params[0]);

                break;
            case 'ob_start':
                if (isset($params[0]) && is_callable($params[0])) {
                    // We can't directly serialize the callback, so we'll just note that one was provided
                    $builder->setAttribute('code.params.has_callback', true);
                }
                if (isset($params[1])) {
                    $builder->setAttribute('code.params.chunk_size', $params[1]);
                }
                if (isset($params[2])) {
                    $builder->setAttribute('code.params.flags', $params[2]);
                }

                break;
        }
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Wordpress;

use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class WordpressInstrumentation
{
    public static function register(CachedInstrumentation $instrumentation): void
    {
        self::_hook('WP', 'main', $instrumentation);
        self::_hook('WP', 'init', $instrumentation);
        self::_hook('WP', 'parse_request', $instrumentation);
        self::_hook('WP', 'send_headers', $instrumentation);
        self::_hook('WP', 'query_posts', $instrumentation);
        self::_hook('WP', 'handle_404', $instrumentation);
        self::_hook('WP', 'register_globals', $instrumentation);
        self::_hook(null, 'get_single_template', $instrumentation);
        self::_hook('wpdb', 'db_connect', $instrumentation);
        self::_hook('wpdb', 'close', $instrumentation);

        /**
         * Database class constructor
         */
        hook(
            class: 'wpdb',
            function: '__construct',
            pre: static function ($object, ?array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = $instrumentation
                    ->tracer()
                    ->spanBuilder('wpdb.__construct')
                    ->setAttribute(TraceAttributes::DB_USER, $params[0] ?? 'unknown')
                    ->setAttribute(TraceAttributes::DB_NAME, $params[1] ?? 'unknown')
                    ->setAttribute(TraceAttributes::DB_SYSTEM, 'mysql')
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {
                self::end($exception);
            }
        );

        /**
         * Create a span for every db query. This can get noisy, so could be turned off via config?
         */
        hook(
            class: 'wpdb',
            function: 'query',
            pre: static function ($object, ?array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = $instrumentation
                    ->tracer()
                    ->spanBuilder('wpdb.query')
                    ->setAttribute(TraceAttributes::DB_STATEMENT, $params[0] ?? 'undefined')
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {
                self::end($exception);
            }
        );
    }

    /**
     * Simple generic hook function which starts and ends a minimal span
     */
    private static function _hook(?string $class, string $function, CachedInstrumentation $instrumentation): void
    {
        hook(
            class: $class,
            function: $function,
            pre: static function ($object, ?array $params, ?string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                self::start($instrumentation, $class, $function, $filename, $lineno);
            },
            post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {
                self::end($exception);
            }
        );
    }

    /**
     * @psalm-suppress ArgumentTypeCoercion
     */
    private static function start(CachedInstrumentation $instrumentation, ?string $class, string $function, ?string $filename, ?int $lineno): void
    {
        $span = $instrumentation->tracer()
            ->spanBuilder(sprintf('%s.%s', $class ?? '<global>', $function))
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('code.function', $function)
            ->setAttribute('code.namespace', $class)
            ->setAttribute('code.filepath', $filename)
            ->setAttribute('code.lineno', $lineno)
            ->startSpan();
        Context::storage()->attach($span->storeInContext(Context::getCurrent()));
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

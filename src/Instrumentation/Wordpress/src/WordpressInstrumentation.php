<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Wordpress;

use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
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
        self::_hook($instrumentation, 'WP', 'main', 'WP.main');
        self::_hook($instrumentation, 'WP', 'init', 'WP.init');
        self::_hook($instrumentation, 'WP', 'parse_request', 'WP.parse_request');
        self::_hook($instrumentation, 'WP', 'send_headers', 'WP.send_headers');
        self::_hook($instrumentation, 'WP', 'query_posts', 'WP.query_posts');
        self::_hook($instrumentation, 'WP', 'handle_404', 'WP.handle_404');
        self::_hook($instrumentation, 'WP', 'register_globals', 'WP.register_globals');
        self::_hook($instrumentation, null, 'get_single_template', 'get_single_template');
        self::_hook($instrumentation, 'wpdb', 'db_connect', 'wpdb.db_connect', SpanKind::KIND_CLIENT);
        self::_hook($instrumentation, 'wpdb', 'close', 'wpdb.close', SpanKind::KIND_CLIENT);

        /**
         * Database class constructor
         */
        hook(
            class: 'wpdb',
            function: '__construct',
            pre: static function ($object, ?array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                self::builder($instrumentation, 'wpdb.__connect', $function, $class, $filename, $lineno)
                    ->setAttribute(TraceAttributes::DB_USER, $params[0] ?? 'unknown')
                    ->setAttribute(TraceAttributes::DB_NAME, $params[1] ?? 'unknown')
                    ->setAttribute(TraceAttributes::DB_SYSTEM, 'mysql')
                    ->startSpan()
                    ->activate();
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
                self::builder($instrumentation, 'wpdb.query', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_STATEMENT, $params[0] ?? 'undefined')
                    ->startSpan()
                    ->activate();
            },
            post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {
                self::end($exception);
            }
        );
    }

    /**
     * Simple generic hook function which starts and ends a minimal span
     * @psalm-param SpanKind::KIND_* $spanKind
     */
    private static function _hook(CachedInstrumentation $instrumentation, ?string $class, string $function, string $name, int $spanKind = SpanKind::KIND_SERVER): void
    {
        hook(
            class: $class,
            function: $function,
            pre: static function ($object, ?array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation, $name, $spanKind) {
                self::builder($instrumentation, $name, $function, $class, $filename, $lineno)
                    ->setSpanKind($spanKind)
                    ->startSpan()
                    ->activate();
            },
            post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {
                self::end($exception);
            }
        );
    }

    private static function builder(
        CachedInstrumentation $instrumentation,
        string $name,
        ?string $function,
        ?string $class,
        ?string $filename,
        ?int $lineno,
    ): SpanBuilderInterface {
        /** @psalm-suppress ArgumentTypeCoercion */
        return $instrumentation->tracer()
            ->spanBuilder($name)
            ->setAttribute('code.function', $function)
            ->setAttribute('code.namespace', $class)
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

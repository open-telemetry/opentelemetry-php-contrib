<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Wordpress;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

/**
 * @phan-file-suppress PhanUndeclaredFunction
 */
class WordpressInstrumentation
{
    public const NAME = 'wordpress';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.wordpress',
            null,
            'https://opentelemetry.io/schemas/1.30.0'
        );

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
                $span = self::builder($instrumentation, 'wpdb.__connect', $function, $class, $filename, $lineno)
                    //->setAttribute(TraceAttributes::DB_USER, $params[0] ?? 'unknown') //deprecated, no replacement
                    ->setAttribute(TraceAttributes::DB_NAMESPACE, $params[2] ?? 'unknown')
                    ->setAttribute(TraceAttributes::DB_SYSTEM_NAME, 'mysql')
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
                $span = self::builder($instrumentation, 'wpdb.query', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_QUERY_TEXT, $params[0] ?? 'undefined')
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {
                self::end($exception);
            }
        );

        //wp_initial_constants is earliest hookable WordPress function that is run once. Here we use it to create the root span
        hook(
            class: null,
            function: 'wp_initial_constants',
            pre: static function () use ($instrumentation) {
                $factory = new Psr17Factory();
                $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
                $parent = Globals::propagator()->extract($request->getHeaders());

                $span = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('%s', $request->getMethod()))
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::URL_FULL, (string) $request->getUri())
                    ->setAttribute(TraceAttributes::URL_SCHEME, $request->getUri()->getScheme())
                    ->setAttribute(TraceAttributes::URL_PATH, $request->getUri()->getPath())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                    ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                    ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                    ->setAttribute(TraceAttributes::CLIENT_ADDRESS, $request->getUri()->getHost())
                    ->setAttribute(TraceAttributes::CLIENT_PORT, $request->getUri()->getPort())
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));

                //register a shutdown function to end root span (@todo, ensure it runs _before_ tracer shuts down)
                register_shutdown_function(function () use ($span) {
                    //@todo there could be other interesting settings from wordpress...
                    function_exists('is_admin') && $span->setAttribute('wp.is_admin', is_admin());

                    if (function_exists('is_404') && is_404()) {
                        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, 404);
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                    //@todo check for other errors?

                    $span->end();
                    $scope = Context::storage()->scope();
                    if (!$scope) {
                        return;
                    }
                    $scope->detach();
                });
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
                $span = self::builder($instrumentation, $name, $function, $class, $filename, $lineno)
                    ->setSpanKind($spanKind)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
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
            ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
            ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
            ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
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
}

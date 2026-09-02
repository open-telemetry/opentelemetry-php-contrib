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
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use OpenTelemetry\SemConv\Version;
use Psr\Http\Message\ServerRequestInterface;
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
            Version::VERSION_1_38_0->url(),
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
            pre: static function ($object, ?array $params, ?string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = self::builder($instrumentation, 'wpdb.__construct', $function, $class, $filename, $lineno)
                    //->setAttribute(TraceAttributes::DB_USER, $params[0] ?? 'unknown') //deprecated, no replacement
                    ->setAttribute(DbAttributes::DB_NAMESPACE, $params[2] ?? 'unknown')
                    ->setAttribute(DbAttributes::DB_SYSTEM_NAME, DbAttributes::DB_SYSTEM_NAME_VALUE_MYSQL)
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
            pre: static function ($object, ?array $params, ?string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = self::builder($instrumentation, 'wpdb.query', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(DbAttributes::DB_QUERY_TEXT, $params[0] ?? 'undefined')
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
                $contentLength = $request->getHeaderLine('Content-Length');

                $span = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('%s %s', $request->getMethod(), self::getScriptNameFromRequest($request)))
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(UrlAttributes::URL_FULL, (string) $request->getUri())
                    ->setAttribute(UrlAttributes::URL_SCHEME, $request->getUri()->getScheme())
                    ->setAttribute(UrlAttributes::URL_PATH, $request->getUri()->getPath())
                    ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                    ->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                    ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                    ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, is_numeric($contentLength) ? (int) $contentLength : null)
                    ->setAttribute(ClientAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                    ->setAttribute(ClientAttributes::SERVER_PORT, $request->getUri()->getPort())
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));

                //register a shutdown function to end root span (@todo, ensure it runs _before_ tracer shuts down)
                register_shutdown_function(function () use ($span) {
                    //@todo there could be other interesting settings from wordpress...
                    function_exists('is_admin') && $span->setAttribute('wp.is_admin', is_admin());

                    $statusCode = http_response_code();
                    if (is_int($statusCode)) {
                        $span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);
                        if ($statusCode >= 500) {
                            $span->setAttribute(ErrorAttributes::ERROR_TYPE, (string) $statusCode);
                            $span->setStatus(StatusCode::STATUS_ERROR);
                        }
                    }

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
    private static function _hook(CachedInstrumentation $instrumentation, ?string $class, string $function, string $name, int $spanKind = SpanKind::KIND_INTERNAL): void
    {
        hook(
            class: $class,
            function: $function,
            pre: static function ($object, ?array $params, ?string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $name, $spanKind) {
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
        string $function,
        ?string $class,
        ?string $filename,
        ?int $lineno,
    ): SpanBuilderInterface {
        $fqn = ($class !== null) ? sprintf('%s::%s', $class, $function) : $function;

        /** @psalm-suppress ArgumentTypeCoercion */
        return $instrumentation->tracer()
            ->spanBuilder($name)
            ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, $fqn)
            ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
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

    private static function getScriptNameFromRequest(ServerRequestInterface $request): string
    {
        return $request->getServerParams()['SCRIPT_NAME'] ?? '/';
    }
}

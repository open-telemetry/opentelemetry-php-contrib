<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use OpenTelemetry\SemConv\Version;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Matcher\Dumper\MatcherDumperInterface;
use Symfony\Component\Routing\Router;

/** @psalm-suppress UnusedClass */
final class SymfonyInstrumentation
{
    public const NAME = 'symfony';

    /**
     * Route name => path template map, written next to the router's own cache files.
     */
    public const ROUTE_PATHS_CACHE_FILE = 'otel_route_paths.php';

    /** @var array<string, array<string, string>> */
    private static array $routePaths = [];

    /** @psalm-suppress PossiblyUnusedMethod */
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.symfony',
            null,
            Version::VERSION_1_38_0->url(),
        );

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            HttpKernel::class,
            'handle',
            pre: static function (
                HttpKernel $kernel,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                $request = ($params[0] instanceof Request) ? $params[0] : null;
                $type = $params[1] ?? HttpKernelInterface::MAIN_REQUEST;
                $method = $request?->getMethod() ?? 'unknown';
                $controller = $request?->attributes?->get('_controller');

                if (!is_callable($controller, true, $controllerName)) {
                    $controllerName = 'sub-request';
                }

                $name = ($type === HttpKernelInterface::SUB_REQUEST)
                    ? sprintf('%s %s', $method, $controllerName)
                    : $method;
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder($name)
                    ->setSpanKind(($type === HttpKernelInterface::SUB_REQUEST) ? SpanKind::KIND_INTERNAL : SpanKind::KIND_SERVER)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);

                $parent = Context::getCurrent();
                if ($request) {
                    $parent = Globals::propagator()->extract($request, RequestPropagationGetter::instance());
                    $span = $builder
                        ->setParent($parent)
                        ->setAttribute(UrlAttributes::URL_FULL, $request->getUri())
                        ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                        ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $request->headers->get('Content-Length'))
                        ->setAttribute(UrlAttributes::URL_SCHEME, $request->getScheme())
                        ->setAttribute(UrlAttributes::URL_PATH, $request->getPathInfo())
                        ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->headers->get('User-Agent'))
                        ->setAttribute(ServerAttributes::SERVER_ADDRESS, $request->getHost())
                        ->setAttribute(ServerAttributes::SERVER_PORT, $request->getPort())
                        ->startSpan();
                    $request->attributes->set(SpanInterface::class, $span);
                } else {
                    $span = $builder->startSpan();
                }
                Context::storage()->attach($span->storeInContext($parent));

                return [$request];
            },
            post: static function (
                HttpKernel $kernel,
                array $params,
                ?Response $response,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }
                $type = $params[1] ?? HttpKernelInterface::MAIN_REQUEST;
                $isSubRequest = ($type === HttpKernelInterface::SUB_REQUEST);

                // MAIN_REQUEST without exception: let terminate() handle everything
                if (!$isSubRequest && null === $exception) {
                    return;
                }

                $span = Span::fromContext($scope->context());
                $scope->detach();

                // Record exception if present
                if (null !== $exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                // Remaining cases are sub-requests and main requests with an
                // exception, which terminate() will not handle: end the span here.
                if ($isSubRequest && null !== $response) {
                    $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                }
                $span->end();
            }
        );

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            HttpKernel::class,
            'terminate',
            post: static function (
                HttpKernel $kernel,
                array $params,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }
                $span = Span::fromContext($scope->context());
                if (!$span->isRecording()) {
                    // Sub-request/exception span was already ended by the handle() hook
                    return;
                }
                $scope->detach();

                $request = ($params[0] instanceof Request) ? $params[0] : null;
                $response = ($params[1] instanceof Response) ? $params[1] : null;
                if (null !== $request) {
                    $routeName = $request->attributes->get('_route', '');

                    if ('' !== $routeName) {
                        /** @psalm-suppress ArgumentTypeCoercion */
                        $span
                            ->updateName(sprintf('%s %s', $request->getMethod(), $routeName))
                            ->setAttribute(HttpAttributes::HTTP_ROUTE, $routeName);
                    }
                }

                if (null !== $exception) {
                    $span->recordException($exception);
                    if (null !== $response && $response->getStatusCode() >= Response::HTTP_INTERNAL_SERVER_ERROR) {
                        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                    }
                }

                if (null === $response) {
                    $span->end();

                    return;
                }

                if ($response->getStatusCode() >= Response::HTTP_INTERNAL_SERVER_ERROR) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }
                $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                $span->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                $contentLength = $response->headers->get('Content-Length');
                /** @psalm-suppress PossiblyFalseArgument */
                if (null === $contentLength && is_string($response->getContent())) {
                    $contentLength = \strlen($response->getContent());
                }

                $span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, $contentLength);

                $prop = Globals::responsePropagator();
                $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());

                $span->end();
            }
        );

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            HttpKernel::class,
            'handleThrowable',
            pre: static function (
                HttpKernel $_kernel,
                array $params,
                string $_class,
                string $_function,
                ?string $_filename,
                ?int $_lineno,
            ): array {
                /** @var \Throwable $throwable */
                $throwable = $params[0];

                Span::getCurrent()
                    ->recordException($throwable);

                return $params;
            },
        );

        // The router calls getMatcherDumperInstance() whenever it (re)builds its matcher cache:
        // on cache:warmup, on a cold cache and, in debug mode, when route resources change.
        // The route collection is already loaded at that point, so dump a route name => path
        // map alongside the router cache for cheap lookups on subsequent requests.
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            Router::class,
            'getMatcherDumperInstance',
            post: static function (Router $router, array $_params, ?MatcherDumperInterface $_dumper, ?\Throwable $exception): void {
                if (null !== $exception) {
                    return;
                }

                try {
                    $cacheDir = $router->getOption('cache_dir');
                    if (is_string($cacheDir) && '' !== $cacheDir) {
                        self::writeRoutePaths(
                            $cacheDir . \DIRECTORY_SEPARATOR . self::ROUTE_PATHS_CACHE_FILE,
                            self::collectRoutePaths($router),
                        );
                    }
                } catch (\Throwable) {
                    // Silently ignore - don't break cache warmup for telemetry
                }
            },
        );

        // Hook into Router to capture route path template
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            Router::class,
            'matchRequest',
            post: static function (
                Router $router,
                array $params,
                ?array $result,
                ?\Throwable $exception
            ): void {
                if (null !== $exception || null === $result) {
                    return;
                }

                $request = $params[0] ?? null;
                if (!$request instanceof Request) {
                    return;
                }

                $routeName = $result['_route'] ?? null;
                if (!is_string($routeName) || '' === $routeName) {
                    return;
                }

                try {
                    $routePath = self::resolveRoutePath($router, $routeName);
                    if (null !== $routePath) {
                        // Store the route path template for use by the terminate hook
                        $request->attributes->set('_route_path', $routePath);
                    }
                } catch (\Throwable) {
                    // Silently ignore - don't break the request for telemetry
                }
            },
        );
    }

    private static function resolveRoutePath(Router $router, string $routeName): ?string
    {
        $cacheDir = $router->getOption('cache_dir');

        // Without a cache_dir the matcher has already loaded the route collection, so this is cheap
        if (!is_string($cacheDir) || '' === $cacheDir) {
            $path = $router->getRouteCollection()->get($routeName)?->getPath();

            return null === $path ? null : self::normalizePath($path);
        }

        $file = $cacheDir . \DIRECTORY_SEPARATOR . self::ROUTE_PATHS_CACHE_FILE;

        if (!isset(self::$routePaths[$file])) {
            /** @psalm-suppress UnresolvableInclude */
            $paths = is_file($file) ? (static fn (): mixed => require $file)() : null;

            if (!is_array($paths)) {
                // Router cache was built without this instrumentation (e.g. warmed up with the
                // extension disabled): build the map once and persist it for later requests
                $paths = self::collectRoutePaths($router);
                self::writeRoutePaths($file, $paths);
            }

            /** @var array<string, string> $paths */
            self::$routePaths[$file] = $paths;
        }

        return self::$routePaths[$file][$routeName] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function collectRoutePaths(Router $router): array
    {
        $paths = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $paths[$name] = self::normalizePath($route->getPath());
        }

        return $paths;
    }

    /**
     * Route::getPath() already strips inline requirements and defaults ("{id<\d+>?1}" => "{id}"),
     * only the "important" marker ("{!id}") remains.
     */
    private static function normalizePath(string $path): string
    {
        return str_replace('{!', '{', $path);
    }

    /**
     * @param array<string, string> $paths
     */
    private static function writeRoutePaths(string $file, array $paths): void
    {
        unset(self::$routePaths[$file]);

        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }

        // Write atomically so concurrent requests never include a partially written file
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (false === @file_put_contents($tmp, '<?php return ' . var_export($paths, true) . ';' . \PHP_EOL)) {
            return;
        }
        @chmod($tmp, 0666 & ~umask());
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return;
        }

        if (\function_exists('opcache_invalidate') && filter_var(\ini_get('opcache.enable'), \FILTER_VALIDATE_BOOL)) {
            @opcache_invalidate($file, true);
        }
    }
}

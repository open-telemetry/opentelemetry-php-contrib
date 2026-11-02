<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Instrumentation\ConfigurationResolver;
use function OpenTelemetry\Instrumentation\hook;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Matcher\Dumper\MatcherDumperInterface;
use Symfony\Component\Routing\Router;

/**
 * Resolves the route path template of a matched route without re-running route
 * loaders on requests served from the routing cache.
 *
 * OpenTelemetry semantic conventions define http.route as the request's path
 * template (for example "/blog/{slug}"), not the Symfony route name (for
 * example "app_blog_show"). Symfony itself only exposes the matched route's
 * name on the request, and resolving the path template requires the route
 * collection: Router::getRouteCollection() re-runs all route loaders on every
 * request when routes are served from the cache, adding several ms per
 * request. Instead, a route name => path template map is dumped next to the
 * router's own cache files whenever the router (re)builds its matcher cache,
 * and the path template is resolved from that map at runtime. If the map is
 * missing, it is rebuilt once and persisted.
 *
 * When enabled, the server span name and the http.route attribute use the
 * resolved path template (for example "GET /blog/{slug}") instead of the
 * Symfony route name (for example "GET app_blog_show"), falling back to the
 * route name when no path template is available.
 *
 * This is disabled unless explicitly enabled via
 * {@see RoutePathCache::OTEL_PHP_SYMFONY_ROUTE_PATH_CACHE}.
 *
 * @psalm-suppress UnusedClass
 */
final class RoutePathCache
{
    /**
     * Route name => path template map, written next to the router's own cache files.
     */
    public const ROUTE_PATHS_CACHE_FILE = 'otel_route_paths.php';

    public const OTEL_PHP_SYMFONY_ROUTE_PATH_CACHE = 'OTEL_PHP_SYMFONY_ROUTE_PATH_CACHE';

    /** @var array<string, array<string, string>> */
    private static array $routePaths = [];

    public static function register(): void
    {
        // The router calls getMatcherDumperInstance() whenever it (re)builds its matcher cache:
        // on cache:warmup, on a cold cache and, in debug mode, when route resources change.
        // The route collection is already loaded at that point, so dump a route name => path
        // map alongside the router cache for cheap lookups on subsequent requests.
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            Router::class,
            'getMatcherDumperInstance',
            post: static function (Router $router, array $_params, ?MatcherDumperInterface $_dumper, ?\Throwable $exception): void {
                if (!self::isEnabled() || null !== $exception) {
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
                if (!self::isEnabled() || null !== $exception || null === $result) {
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

    public static function isEnabled(): bool
    {
        /** @phan-suppress-next-line PhanDeprecatedClass */
        $resolver = new ConfigurationResolver();

        return $resolver->getBoolean(self::OTEL_PHP_SYMFONY_ROUTE_PATH_CACHE) ?? false;
    }

    /**
     * The route path template of the matched route if the feature is enabled
     * and the template is known, otherwise the Symfony route name. Used for
     * the span name and the http.route attribute of server spans.
     */
    public static function resolveHttpRoute(Request $request): string
    {
        $routeName = $request->attributes->get('_route', '');
        if (!is_string($routeName)) {
            return '';
        }

        if (self::isEnabled()) {
            $routePath = $request->attributes->get('_route_path', '');
            if (is_string($routePath) && '' !== $routePath) {
                return $routePath;
            }
        }

        return $routeName;
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

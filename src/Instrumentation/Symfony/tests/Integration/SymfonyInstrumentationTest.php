<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Contrib\Instrumentation\Symfony\SymfonyInstrumentation;
use OpenTelemetry\Contrib\Propagation\TraceResponse\TraceResponsePropagator;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\Fixtures\CountingRouteLoader;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;

class SymfonyInstrumentationTest extends AbstractTest
{
    /** @var list<string> */
    private array $cacheDirs = [];

    public function test_http_kernel_handle_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $kernel = $this->getHttpKernel(new EventDispatcher(), function () {
            throw new \RuntimeException();
        });
        $this->assertCount(0, $this->storage);

        $response = $kernel->handle(new Request());
        $kernel->terminate(new Request(), $response);

        $this->assertArrayHasKey(
            TraceResponsePropagator::TRACERESPONSE,
            $response->headers->all(),
            'traceresponse header is present if TraceResponsePropagator is present'
        );
    }

    public function test_http_kernel_marks_root_as_erroneous(): void
    {
        $this->expectException(HttpException::class);
        $kernel = $this->getHttpKernel(new EventDispatcher(), function () {
            throw new HttpException(500, 'foo');
        });
        $this->assertCount(0, $this->storage);

        $response = $kernel->handle(new Request(), HttpKernelInterface::MAIN_REQUEST, true);
        $kernel->terminate(new Request(), $response);

        $this->assertCount(1, $this->storage);
        $this->assertSame(500, $this->storage[0]->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));

        $this->assertSame(StatusCode::STATUS_ERROR, $this->storage[0]->getStatus()->getCode());
    }

    public function test_http_kernel_handle_attributes(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());
        $this->assertCount(0, $this->storage);
        $request = new Request();
        // When _route_path is available, it should be used for http.route (per OpenTelemetry semantic conventions)
        $request->attributes->set('_route', 'test_route');
        $request->attributes->set('_route_path', '/test/{id}');

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $attributes = $this->storage[0]->getAttributes();
        $this->assertCount(1, $this->storage);
        $this->assertEquals('GET /test/{id}', $this->storage[0]->getName());
        $this->assertEquals('http://:/', $attributes->get(UrlAttributes::URL_FULL));
        $this->assertEquals('GET', $attributes->get(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertEquals('http', $attributes->get(UrlAttributes::URL_SCHEME));
        $this->assertEquals('/test/{id}', $attributes->get(HttpAttributes::HTTP_ROUTE));
        $this->assertEquals(200, $attributes->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertEquals('1.0', $attributes->get(NetworkAttributes::NETWORK_PROTOCOL_VERSION));
        $this->assertEquals(5, $attributes->get(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE));
    }

    public function test_http_kernel_handle_fallback_to_route_name(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());
        $this->assertCount(0, $this->storage);
        $request = new Request();
        // When _route_path is not available, fall back to _route (route name)
        $request->attributes->set('_route', 'test_route');

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $this->assertCount(1, $this->storage);
        $attributes = $this->storage[0]->getAttributes();
        $this->assertEquals('GET test_route', $this->storage[0]->getName());
        $this->assertEquals('test_route', $attributes->get(HttpAttributes::HTTP_ROUTE));
    }

    public function test_router_match_request_hook_populates_route_path_on_same_request(): void
    {
        $routes = new RouteCollection();
        $routes->add('test_route', new Route('/test/{id}'));

        $loader = new class($routes) extends Loader {
            public function __construct(private readonly RouteCollection $routes)
            {
            }

            public function load(mixed $resource, ?string $type = null): mixed
            {
                return $this->routes;
            }

            public function supports(mixed $resource, ?string $type = null): bool
            {
                return true;
            }
        };

        $router = new Router($loader, 'routes', [], new RequestContext());

        $request = Request::create('http://localhost/test/42');
        $this->assertFalse($request->attributes->has('_route_path'));

        $result = $router->matchRequest($request);
        $request->attributes->add($result); // as Symfony's RouterListener does

        // The post hook ran and mutated our $request instance in place
        $this->assertSame('test_route', $result['_route']);
        $this->assertSame('/test/{id}', $request->attributes->get('_route_path'));

        // The HttpKernel::terminate hook picks up the route path template
        $kernel = $this->getHttpKernel(new EventDispatcher());
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $this->assertCount(1, $this->storage);
        $this->assertSame('GET /test/{id}', $this->storage[0]->getName());
        $this->assertSame('/test/{id}', $this->storage[0]->getAttributes()->get(HttpAttributes::HTTP_ROUTE));
    }

    /**
     * @dataProvider cachedRouteProvider
     */
    public function test_router_match_request_hook_resolves_route_path_from_cache_without_loading_routes(string $uri, string $expectedPath): void
    {
        $cacheDir = $this->createCacheDir();

        // Warm the routing cache, as `cache:warmup` does in production
        $warmLoader = new CountingRouteLoader(self::createRoutes());
        (new Router($warmLoader, 'routes', ['cache_dir' => $cacheDir], new RequestContext()))->getMatcher();
        $this->assertSame(1, $warmLoader->loads);
        $this->assertFileExists($cacheDir . '/' . SymfonyInstrumentation::ROUTE_PATHS_CACHE_FILE);

        // A fresh router (i.e. a new request) served from the warm cache
        $loader = new CountingRouteLoader(self::createRoutes());
        $router = new Router($loader, 'routes', ['cache_dir' => $cacheDir], new RequestContext());

        $request = Request::create('http://localhost' . $uri);
        $router->matchRequest($request);

        $this->assertSame($expectedPath, $request->attributes->get('_route_path'));
        $this->assertSame(0, $loader->loads, 'route resources must not be loaded when serving from the routing cache');
    }

    public static function cachedRouteProvider(): iterable
    {
        yield 'root' => ['/', '/'];
        yield 'static' => ['/about/us', '/about/us'];
        yield 'single variable' => ['/test/42', '/test/{id}'];
        yield 'multiple variables' => ['/blog/hello/2', '/blog/{slug}/{page}'];
        yield 'optional variable omitted' => ['/blog/hello', '/blog/{slug}/{page}'];
        yield 'inline requirement and default' => ['/files/readme.json', '/files/{name}.{_format}'];
        yield 'important variable' => ['/important/foo', '/important/{slug}'];
    }

    public function test_router_match_request_hook_rebuilds_missing_route_paths_cache_once(): void
    {
        $cacheDir = $this->createCacheDir();
        $routePathsFile = $cacheDir . '/' . SymfonyInstrumentation::ROUTE_PATHS_CACHE_FILE;

        // Router cache warmed without the route paths map, e.g. with the extension disabled
        (new Router(new CountingRouteLoader(self::createRoutes()), 'routes', ['cache_dir' => $cacheDir], new RequestContext()))->getMatcher();
        unlink($routePathsFile);

        $loader = new CountingRouteLoader(self::createRoutes());
        $request = Request::create('http://localhost/test/42');
        (new Router($loader, 'routes', ['cache_dir' => $cacheDir], new RequestContext()))->matchRequest($request);

        $this->assertSame('/test/{id}', $request->attributes->get('_route_path'));
        $this->assertSame(1, $loader->loads);
        $this->assertFileExists($routePathsFile);

        $loader = new CountingRouteLoader(self::createRoutes());
        $request = Request::create('http://localhost/test/42');
        (new Router($loader, 'routes', ['cache_dir' => $cacheDir], new RequestContext()))->matchRequest($request);

        $this->assertSame('/test/{id}', $request->attributes->get('_route_path'));
        $this->assertSame(0, $loader->loads);
    }

    public function test_router_match_request_hook_refreshes_route_paths_when_routes_change_in_debug(): void
    {
        $cacheDir = $this->createCacheDir();
        mkdir($cacheDir);
        $resource = $cacheDir . '/routes.resource';
        touch($resource, time() - 10);

        $routes = new RouteCollection();
        $routes->add('test_route', new Route('/test/{id}'));
        $routes->addResource(new FileResource($resource));
        $options = ['cache_dir' => $cacheDir, 'debug' => true];

        $request = Request::create('http://localhost/test/42');
        (new Router(new CountingRouteLoader($routes), 'routes', $options, new RequestContext()))->matchRequest($request);
        $this->assertSame('/test/{id}', $request->attributes->get('_route_path'));

        // The route definition changes, which in debug mode invalidates the router cache
        $changedRoutes = new RouteCollection();
        $changedRoutes->add('test_route', new Route('/test/{slug}'));
        $changedRoutes->addResource(new FileResource($resource));
        touch($resource, time() + 10);
        clearstatcache();

        $request = Request::create('http://localhost/test/42');
        (new Router(new CountingRouteLoader($changedRoutes), 'routes', $options, new RequestContext()))->matchRequest($request);
        $this->assertSame('/test/{slug}', $request->attributes->get('_route_path'));
    }

    private static function createRoutes(): RouteCollection
    {
        $routes = new RouteCollection();
        $routes->add('home', new Route('/'));
        $routes->add('static_route', new Route('/about/us'));
        $routes->add('test_route', new Route('/test/{id}', [], ['id' => '\d+']));
        $routes->add('multi_route', new Route('/blog/{slug}/{page}', ['page' => 1]));
        $routes->add('inline_route', new Route('/files/{name<[a-z]+>?index}.{_format}'));
        $routes->add('important_route', new Route('/important/{!slug}'));

        return $routes;
    }

    private function createCacheDir(): string
    {
        $dir = sys_get_temp_dir() . '/otel-symfony-router-' . bin2hex(random_bytes(4));
        $this->cacheDirs[] = $dir;

        return $dir;
    }

    public function tearDown(): void
    {
        foreach ($this->cacheDirs as $dir) {
            self::removeDirectory($dir);
        }
        $this->cacheDirs = [];

        parent::tearDown();
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    public function test_http_kernel_handle_stream_response(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher(), fn () => new StreamedResponse(function () {
            echo 'Hello';
            flush();
        }));
        $this->assertCount(0, $this->storage);

        $response = $kernel->handle(new Request());
        $kernel->terminate(new Request(), $response);

        $this->assertCount(1, $this->storage);
        $this->assertNull($this->storage[0]->getAttributes()->get(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE));
    }

    public function test_http_kernel_handle_binary_file_response(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher(), fn () => new BinaryFileResponse(__FILE__));
        $this->assertCount(0, $this->storage);

        $response = $kernel->handle(new Request());
        $kernel->terminate(new Request(), $response);

        $this->assertCount(1, $this->storage);
        $this->assertNull($this->storage[0]->getAttributes()->get(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE));

    }

    public function test_http_kernel_handle_with_empty_route(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());
        $this->assertCount(0, $this->storage);
        $request = new Request();
        $request->attributes->set('_route', '');

        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $kernel->terminate(new Request(), $response);

        $this->assertCount(1, $this->storage);
        $this->assertFalse($this->storage[0]->getAttributes()->has(HttpAttributes::HTTP_ROUTE));

    }

    public function test_http_kernel_handle_without_route(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());
        $this->assertCount(0, $this->storage);

        $response = $kernel->handle(new Request(), HttpKernelInterface::MAIN_REQUEST, true);
        $kernel->terminate(new Request(), $response);

        $this->assertCount(1, $this->storage);
        $this->assertFalse($this->storage[0]->getAttributes()->has(HttpAttributes::HTTP_ROUTE));

    }

    public function test_http_kernel_handle_subrequest(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());
        $this->assertCount(0, $this->storage);
        $request = new Request();
        $request->attributes->set('_controller', 'ErrorController');

        $response = $kernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        $kernel->terminate($request, $response);

        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $this->assertSame('GET ErrorController', $span->getName());
        $this->assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
    }

    public function test_http_kernel_handle_subrequest_with_various_controller_types(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());

        // String controller
        $request = new Request();
        $request->attributes->set('_controller', 'SomeController::index');
        $response = $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        $kernel->terminate($request, $response);
        $this->assertSame('GET SomeController::index', $this->storage[0]->getName());
        $this->storage->exchangeArray([]);

        // Array: [object, method]
        $controllerObj = new class() {};
        $request = new Request();
        $request->attributes->set('_controller', [$controllerObj, 'fooAction']);
        $response = $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        $kernel->terminate($request, $response);
        $this->assertSame('GET ' . get_class($controllerObj) . '::fooAction', $this->storage[0]->getName());
        $this->storage->exchangeArray([]);

        // Array: [class, method]
        $request = new Request();
        $request->attributes->set('_controller', ['SomeClass', 'barAction']);
        $response = $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        $kernel->terminate($request, $response);
        $this->assertSame('GET SomeClass::barAction', $this->storage[0]->getName());
        $this->storage->exchangeArray([]);
    }

    /**
     * @psalm-suppress UnevaluatedCode
     */
    public function test_http_kernel_handle_subrequest_with_null_and_object_controller(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());

        // Object controller  (should fallback to 'sub-request')
        $controllerObj2 = new class() {};
        $request = new Request();
        $request->attributes->set('_controller', $controllerObj2);
        $response = $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        $kernel->terminate($request, $response);
        $this->assertSame('GET sub-request', $this->storage[0]->getName());

        // Null/other controller (should fallback to 'sub-request')
        $request = new Request();
        $request->attributes->set('_controller', null);
        $response = $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        $kernel->terminate($request, $response);
        $this->assertSame('GET sub-request', $this->storage[0]->getName());
    }

    public function test_http_kernel_handle_subrequest_exception_is_recorded_and_span_ended(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher(), function () {
            throw new \RuntimeException('sub-request failed');
        });
        $request = new Request();
        $request->attributes->set('_controller', 'FailingController');

        try {
            // catch=false: exception propagates directly, matching HttpKernel::handleRaw failures
            $kernel->handle($request, HttpKernelInterface::SUB_REQUEST, false);
            $this->fail('Expected RuntimeException to be thrown');
        } catch (\RuntimeException) {
            // expected
        }

        // The sub-request span is ended by the handle() hook itself,
        // without waiting for terminate() (which only handles the main request)
        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertSame('GET FailingController', $span->getName());
        $this->assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('sub-request failed', $span->getStatus()->getDescription());

        $events = $span->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame('exception', $events[0]->getName());
    }

    public function test_http_kernel_handle_uncaught_exception_ends_span(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher(), function () {
            throw new \RuntimeException('something went wrong');
        });

        try {
            $kernel->handle(new Request());
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('something went wrong', $span->getStatus()->getDescription());
    }

    public function test_http_kernel_handle_5xx_response_is_error(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher(), fn () => new Response('Internal Server Error', 500));

        $response = $kernel->handle(new Request());
        $kernel->terminate(new Request(), $response);

        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(500, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    private function getHttpKernel(EventDispatcherInterface $eventDispatcher, $controller = null, ?RequestStack $requestStack = null, array $arguments = []): HttpKernel
    {
        $controller ??= fn () => new Response('Hello');

        $controllerResolver = $this->createMock(ControllerResolverInterface::class);
        $controllerResolver
            ->method('getController')
            ->willReturn($controller);

        $argumentResolver = $this->createMock(ArgumentResolverInterface::class);
        $argumentResolver
            ->method('getArguments')
            ->willReturn($arguments);

        return new HttpKernel($eventDispatcher, $controllerResolver, $requestStack, $argumentResolver);
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Middleware;

use Exception;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;

/** @psalm-suppress UnusedClass */
class MiddlewareTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        
        // Ensure the storage is reset but don't override the type
        $this->storage->exchangeArray([]);
        
        // Make sure our instrumentation is actually enabled
        // We might need to mark this test as skipped if the Middleware
        // instrumentation is not actually registered
    }

    public function test_it_creates_span_for_middleware(): void
    {   
        $router = $this->router();
        // Define a test middleware
        $router->aliasMiddleware('test-middleware', function ($request, $next) {
            // Do something in the middleware
            $request->attributes->set('middleware_was_here', true);
            return $next($request);
        });
        
        // Define a route with the middleware
        $router->middleware(['test-middleware'])->get('/middleware-test', function () {
            return 'Middleware Test Route';
        });

        // Make a request to the route with middleware
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/middleware-test');
        
        // Basic response checks
        $this->assertEquals(200, $response->status());
        $this->assertEquals('Middleware Test Route', $response->getContent());

        // Debug: Print out actual spans
        $this->printSpans();
        
        $this->assertTraceStructure([
            [
                'name' => 'GET /middleware-test',
                'attributes' => [
                    'code.function.name' => 'handle',
                    'code.namespace' => 'Illuminate\Foundation\Http\Kernel',
                    'url.full' => 'http://localhost/middleware-test',
                    'http.request.method' => 'GET',
                    'url.scheme' => 'http',
                    'network.protocol.version' => '1.1',
                    'network.peer.address' => '127.0.0.1',
                    'url.path' => 'middleware-test',
                    'server.address' => 'localhost',
                    'server.port' => 80,
                    'user_agent.original' => 'Symfony',
                    'http.route' => 'middleware-test',
                    'http.response.status_code' => 200,
                ],
                'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_SERVER,
                'children' => [
                    [
                        'name' => 'Illuminate\Foundation\Http\Middleware\ValidatePostSize::handle',
                        'attributes' => [
                            'laravel.middleware.class' => 'Illuminate\Foundation\Http\Middleware\ValidatePostSize',
                            'http.response.status_code' => 200,
                        ],
                        'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
                        'children' => [
                            [
                                'name' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::handle',
                                'attributes' => [
                                    'laravel.middleware.class' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
                                    'http.response.status_code' => 200,
                                ],
                                'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
    
    public function test_it_adds_response_attributes_when_middleware_returns_response(): void
    {
        $router = $this->router();

        // Define a middleware that returns a response
        $router->aliasMiddleware('response-middleware', function ($request, $next) {
            // Return a response directly from middleware
            return response('Response from middleware', 403);
        });
        
        // Define a route with the middleware
        $router->middleware(['response-middleware'])->get('/middleware-response', function () {
            return 'This should not be reached';
        });

        // Make a request to the route
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/middleware-response');
        
        // Check that the middleware response was returned
        $this->assertEquals(403, $response->status());
        $this->assertEquals('Response from middleware', $response->getContent());

        // Debug: Print out actual spans
        $this->printSpans();
        
        $this->assertTraceStructure([
            [
                'name' => 'GET /middleware-response',
                'attributes' => [
                    'code.function.name' => 'handle',
                    'code.namespace' => 'Illuminate\Foundation\Http\Kernel',
                    'url.full' => 'http://localhost/middleware-response',
                    'http.request.method' => 'GET',
                    'url.scheme' => 'http',
                    'network.protocol.version' => '1.1',
                    'network.peer.address' => '127.0.0.1',
                    'url.path' => 'middleware-response',
                    'server.address' => 'localhost',
                    'server.port' => 80,
                    'user_agent.original' => 'Symfony',
                    'http.route' => 'middleware-response',
                    'http.response.status_code' => 403,
                ],
                'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_SERVER,
                'children' => [
                    [
                        'name' => 'Illuminate\Foundation\Http\Middleware\ValidatePostSize::handle',
                        'attributes' => [
                            'laravel.middleware.class' => 'Illuminate\Foundation\Http\Middleware\ValidatePostSize',
                            'http.response.status_code' => 403,
                        ],
                        'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
                        'children' => [
                            [
                                'name' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::handle',
                                'attributes' => [
                                    'code.function.name' => 'handle',
                                    'code.namespace' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
                                    'laravel.middleware.class' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
                                    'http.response.status_code' => 403,
                                ],
                                'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
    
    public function test_it_records_exceptions_in_middleware(): void
    {
        $router = $this->router();

        // Define a middleware that throws an exception
        $router->aliasMiddleware('exception-middleware', function ($request, $next) {
            throw new Exception('Middleware Exception');
        });
        
        // Define a route with the middleware
        $router->middleware(['exception-middleware'])->get('/middleware-exception', function () {
            return 'This should not be reached';
        });

        // Make a request to the route
        $this->storage->exchangeArray([]);
        $response = $this->call('GET', '/middleware-exception');
        
        // Laravel should catch the exception and return a 500 response
        $this->assertEquals(500, $response->status());

        // Debug: Print out actual spans
        $this->printSpans();
        
        $this->assertTraceStructure([
            [
                'name' => 'GET /middleware-exception',
                'attributes' => [
                    'code.function.name' => 'handle',
                    'code.namespace' => 'Illuminate\Foundation\Http\Kernel',
                    'url.full' => 'http://localhost/middleware-exception',
                    'http.request.method' => 'GET',
                    'url.scheme' => 'http',
                    'network.protocol.version' => '1.1',
                    'network.peer.address' => '127.0.0.1',
                    'url.path' => 'middleware-exception',
                    'server.address' => 'localhost',
                    'server.port' => 80,
                    'user_agent.original' => 'Symfony',
                    'http.route' => 'middleware-exception',
                    'http.response.status_code' => 500,
                ],
                'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_SERVER,
                'children' => [
                    [
                        'name' => 'Illuminate\Foundation\Http\Middleware\ValidatePostSize::handle',
                        'attributes' => [
                            'laravel.middleware.class' => 'Illuminate\Foundation\Http\Middleware\ValidatePostSize',
                            'http.response.status_code' => 500,
                        ],
                        'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
                        'children' => [
                            [
                                'name' => 'Illuminate\Foundation\Exceptions\Handler@render',
                                'attributes' => [
                                    'code.function.name' => 'handle',
                                    'code.namespace' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
                                    'laravel.middleware.class' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
                                    'exception.class' => 'Exception',
                                    'exception.message' => 'Middleware Exception',
                                    'http.response.status_code' => 500,
                                ],
                                'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
    
    public function test_it_handles_middleware_groups(): void
    {
        $router = $this->router();

        // Define test middleware classes
        $router->aliasMiddleware('middleware-1', \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class);
        $router->aliasMiddleware('middleware-2', \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class);
        
        // Define a middleware group
        $router->middlewareGroup('test-group', [
            'middleware-1',
            'middleware-2',
        ]);
        
        // Define a route with the middleware group
        $router->middleware(['test-group'])->get('/middleware-group', function () {
            return 'Middleware Group Test';
        });

        // Make a request to the route
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/middleware-group');
        
        // Basic response checks
        $this->assertEquals(200, $response->status());
        
        // Debug: Print out actual spans
        $this->printSpans();
        
        $this->assertTraceStructure([
            [
                'name' => 'GET /middleware-group',
                'attributes' => [
                    'code.function.name' => 'handle',
                    'code.namespace' => 'Illuminate\Foundation\Http\Kernel',
                    'url.full' => 'http://localhost/middleware-group',
                    'http.request.method' => 'GET',
                    'url.scheme' => 'http',
                    'network.protocol.version' => '1.1',
                    'network.peer.address' => '127.0.0.1',
                    'url.path' => 'middleware-group',
                    'server.address' => 'localhost',
                    'server.port' => 80,
                    'user_agent.original' => 'Symfony',
                    'http.route' => 'middleware-group',
                    'http.response.status_code' => 200,
                ],
                'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_SERVER,
                'children' => [
                    [
                        'name' => 'Illuminate\Foundation\Http\Middleware\ValidatePostSize::handle',
                        'attributes' => [
                            'laravel.middleware.class' => 'Illuminate\Foundation\Http\Middleware\ValidatePostSize',
                            'http.response.status_code' => 200,
                        ],
                        'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
                        'children' => [
                            [
                                'name' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::handle',
                                'attributes' => [
                                    'code.function.name' => 'handle',
                                    'code.namespace' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
                                    'laravel.middleware.class' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
                                    'http.response.status_code' => 200,
                                ],
                                'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
    
    public function test_it_handles_middleware_terminate_method(): void
    {
        // For now, we'll just check that a request with Laravel's built-in
        // middleware (which has terminate methods) works properly
        
        // Define a basic route (Laravel will apply its default middleware)
        $this->router()->get('/middleware-terminate', function () {
            return 'Testing terminate middleware';
        });

        // Make a request to the route
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/middleware-terminate');
        
        // Basic response checks
        $this->assertEquals(200, $response->status());
        $this->assertEquals('Testing terminate middleware', $response->getContent());
        
        // We should have spans now 
        $this->assertGreaterThan(0, count($this->storage));
        
        // The actual assertions here would depend on how terminate middleware is instrumented
        // We're mainly checking that the request completes successfully
        $this->assertGreaterThan(0, count($this->storage), 'No spans were recorded');
    }

    private function router(): Router
    {
        /** @psalm-suppress PossiblyNullReference */
        return $this->app->make(Router::class);
    }
} 
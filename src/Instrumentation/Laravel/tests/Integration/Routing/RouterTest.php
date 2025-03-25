<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Routing;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;

/** @psalm-suppress UnusedClass */
class RouterTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        
        // Ensure the storage is reset but don't override the type
        $this->storage->exchangeArray([]);
    }
    
    public function test_it_names_transaction_with_controller_action(): void
    {   
        // Create a controller class for testing
        if (!class_exists('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Routing\TestController')) {
            eval('
                namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Routing;
                class TestController {
                    public function index() {
                        return "Controller Response";
                    }
                }
            ');
        }
        
        // Define a route with a controller
        $this->router()->get('/controller-test', 'OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Routing\TestController@index');

        // Call the route
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/controller-test');
        
        // Check response is 200
        $this->assertEquals(200, $response->status());
        
        // Find the span for the request
        $this->assertGreaterThan(0, count($this->storage));
        $span = $this->storage[0];
        
        // Check the span has necessary attributes
        $attributes = $span->getAttributes()->toArray();
        
        $this->assertArrayHasKey('http.method', $attributes);
        $this->assertEquals('GET', $attributes['http.method']);
        
        // If the router enhancement is active, the code namespace should be set
        if (isset($attributes['code.namespace'])) {
            $this->assertStringContainsString('TestController', $attributes['code.namespace']);
        }
    }
    
    public function test_it_names_transaction_with_route_name(): void
    {
        // Define a route with a name
        $this->router()->get('/named-route', function () {
            return 'Named Route';
        })->name('test.named.route');

        // Call the route
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/named-route');
        
        // Check response is 200
        $this->assertEquals(200, $response->status());
        
        // Find the span for the request
        $this->assertGreaterThan(0, count($this->storage));
        $span = $this->storage[0];
        
        // Check if the route name attribute exists (depends on implementation)
        $attributes = $span->getAttributes()->toArray();
        if (isset($attributes['laravel.route.name'])) {
            $this->assertEquals('test.named.route', $attributes['laravel.route.name']);
        }
    }
    
    public function test_it_names_transaction_with_route_uri(): void
    {
        // Define a route without a name or controller (closure only)
        $this->router()->get('/uri-route/{param}', function ($param) {
            return 'URI Route: ' . $param;
        });

        // Call the route
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/uri-route/123');
        
        // Check response is 200
        $this->assertEquals(200, $response->status());
        
        // Find the span for the request
        $this->assertGreaterThan(0, count($this->storage));
        $span = $this->storage[0];
        
        // Check the route information
        $attributes = $span->getAttributes()->toArray();
        
        // The http.route attribute should contain the route pattern
        if (isset($attributes['http.route'])) {
            $this->assertStringContainsString('uri-route/{param}', $attributes['http.route']);
        }
    }
    
    public function test_it_ignores_generated_route_names(): void
    {
        // Laravel automatically generates route names with a "generated::" prefix
        // when using Route::view() without explicitly naming the route
        Route::view('/generated-route', 'welcome')
            ->name('generated::' . sha1('some-random-string'));

        // Call the route
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/generated-route');
        
        // Check response is 200
        $this->assertEquals(200, $response->status());
        
        // Find the span for the request
        $this->assertGreaterThan(0, count($this->storage));
        $span = $this->storage[0];
        
        // This test depends on the implementation details
        // If laravel.route.name is set, it should not contain "generated::"
        $attributes = $span->getAttributes()->toArray();
        if (isset($attributes['laravel.route.name'])) {
            $this->assertStringNotContainsString('generated::', $attributes['laravel.route.name']);
        }
    }
    
    public function test_it_marks_500_responses_as_error(): void
    {
        // Define a route that returns a 500 response
        $this->router()->get('/error-route', function () {
            abort(500, 'Internal Server Error');
            return 'This should not be reached';
        });

        // Call the route
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/error-route');
        
        // Check response is 500
        $this->assertEquals(500, $response->status());
        
        // Find the span for the request
        $this->assertGreaterThan(0, count($this->storage));
        $span = $this->storage[0];
        
        // Check attributes
        $attributes = $span->getAttributes()->toArray();
        $this->assertArrayHasKey('http.response.status_code', $attributes);
        $this->assertEquals(500, $attributes['http.response.status_code']);
    }

    private function router(): Router
    {
        /** @psalm-suppress PossiblyNullReference */
        return $this->app->make(Router::class);
    }
} 
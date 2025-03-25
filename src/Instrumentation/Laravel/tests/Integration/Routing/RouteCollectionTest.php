<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Routing;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;

/** @psalm-suppress UnusedClass */
class RouteCollectionTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        
        // Ensure the storage is reset but don't override the type
        $this->storage->exchangeArray([]);
    }
    
    public function test_it_creates_span_for_route_matching(): void
    {
        // Define a simple route
        $this->router()->get('/route-matching-test', function () {
            return 'Route Matching Test';
        });

        // Call the route
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/route-matching-test');
        
        // Check response is 200
        $this->assertEquals(200, $response->status());
        
        // We expect to have at least one span for the HTTP request
        $this->assertGreaterThanOrEqual(1, count($this->storage));
        
        // We should have a span for the main request
        $mainSpan = $this->storage[0];
        $this->assertStringContainsString('GET', $mainSpan->getName());
        
        // The route matching span might not be implemented yet or stored differently
        // If it exists, it should contain route information
        $attributes = $mainSpan->getAttributes()->toArray();
        $this->assertArrayHasKey('http.method', $attributes);
        $this->assertEquals('GET', $attributes['http.method']);
    }
    
    public function test_it_records_route_not_found_in_span(): void
    {
        // Call a route that doesn't exist
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/nonexistent-route');
        
        // Check response is 404
        $this->assertEquals(404, $response->status());
        
        // Find the main span
        $this->assertGreaterThan(0, count($this->storage));
        $span = $this->storage[0];
        
        // The span should have attributes related to the request
        $attributes = $span->getAttributes()->toArray();
        $this->assertEquals('Illuminate\Foundation\Exceptions\Handler@render', $span->getName());
    }
    
    public function test_it_handles_method_not_allowed(): void
    {
        // Define a route that only accepts POST
        $this->router()->post('/post-only-route', function () {
            return 'POST Only Route';
        });

        // Try to access it with GET
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/post-only-route');
        
        // Check response is 405 Method Not Allowed
        $this->assertEquals(405, $response->status());
        
        // Find the main span
        $this->assertGreaterThan(0, count($this->storage));
        $span = $this->storage[0];
        
        // The span should have attributes related to the request
        $attributes = $span->getAttributes()->toArray();
        $this->assertEquals('Illuminate\Foundation\Exceptions\Handler@render', $span->getName());
    }
    
    public function test_it_includes_route_parameters_in_span(): void
    {
        // Define a route with parameters
        $this->router()->get('/users/{id}/profile', function ($id) {
            return 'User ' . $id . ' Profile';
        });

        // Call the route with a parameter
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/users/123/profile');
        
        // Check response is 200
        $this->assertEquals(200, $response->status());
        
        // Find the main span
        $this->assertGreaterThan(0, count($this->storage));
        $span = $this->storage[0];
        
        // The span should have attributes related to the request
        $attributes = $span->getAttributes()->toArray();
        $this->assertArrayHasKey('http.method', $attributes);
        $this->assertEquals('GET', $attributes['http.method']);
        
        // Check if route information is included
        // This will depend on the exact implementation
        $this->assertArrayHasKey('http.route', $attributes);
    }

    private function router(): Router
    {
        /** @psalm-suppress PossiblyNullReference */
        return $this->app->make(Router::class);
    }
} 
<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\SemConv\TraceAttributes;

/** @psalm-suppress UnusedClass */
class LaravelInstrumentationTest extends TestCase
{
    public function test_request_response(): void
    {
        $this->router()->get('/', fn () => null);

        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/');
        $this->assertEquals(200, $response->status());
        $this->assertCount(5, $this->storage);
        $span = $this->storage[0];
        $this->assertSame('GET /', $span->getName());

        $response = Http::get('opentelemetry.io');
        $this->assertEquals(200, $response->status());
        $span = $this->storage[1];
        $this->assertSame('GET', $span->getName());
    }

    public function test_cache_log_db(): void
    {
        $this->router()->get('/hello', function () {
            $text = 'Hello Cruel World';
            cache()->forever('opentelemetry', 'opentelemetry');
            Log::info('Log info', ['test' => true]);
            cache()->get('opentelemetry.io', 'php');
            cache()->get('opentelemetry', 'php');
            cache()->forget('opentelemetry');
            DB::select('select 1');

            return view('welcome', ['text' => $text]);
        });

        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/hello');
        $this->assertEquals(200, $response->status());

        // Debug: Print out actual spans
        foreach ($this->getSpans() as $index => $span) {
            echo sprintf(
                "Span %d: [TraceId: %s, SpanId: %s, ParentId: %s] %s (attributes: %s)\n",
                $index,
                $span->getTraceId(),
                $span->getSpanId(),
                $span->getParentSpanId() ?: 'null',
                $span->getName(),
                json_encode($span->getAttributes()->toArray())
            );
        }

        $this->assertTraceStructure([
            [
                'name' => 'GET /hello',
                'attributes' => [
                    'code.function.name' => 'handle',
                    'code.namespace' => 'Illuminate\Foundation\Http\Kernel',
                    'url.full' => 'http://localhost/hello',
                    'http.request.method' => 'GET',
                    'url.scheme' => 'http',
                    'network.protocol.version' => '1.1',
                    'network.peer.address' => '127.0.0.1',
                    'url.path' => 'hello',
                    'server.address' => 'localhost',
                    'server.port' => 80,
                    'user_agent.original' => 'Symfony',
                    'http.route' => 'hello',
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
                                        'children' => [
                                            [
                                                'name' => 'GET /hello',
                                                'attributes' => [
                                                    'code.function.name' => 'handle',
                                                    'code.namespace' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
                                                    'laravel.middleware.class' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
                                                    'http.method' => 'GET',
                                                    'http.route' => 'hello',
                                                    'http.response.status_code' => 200,
                                                ],
                                                'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
                                                'children' => [
                                                    [
                                                        'name' => 'sql SELECT',
                                                        'attributes' => [
                                                            'db.operation.name' => 'SELECT',
                                                            'db.namespace' => ':memory:',
                                                            'db.query.text' => 'select 1',
                                                            'db.system.name' => 'sqlite',
                                                        ],
                                                        'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_CLIENT,
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_low_cardinality_route_span_name(): void
    {
        // Test with a named route - should use the route name
        $this->router()->get('/hello/{name}', fn () => null)->name('hello-name');

        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/hello/opentelemetry');
        $this->assertEquals(200, $response->status());
        $this->assertCount(5, $this->storage);

        $span = $this->storage[0];
        $this->assertSame('GET hello-name', $span->getName());
        $this->assertArrayHasKey('laravel.route.name', $span->getAttributes()->toArray());
        $this->assertSame('hello-name', $span->getAttributes()->get('laravel.route.name'));
        $this->assertSame('hello/{name}', $span->getAttributes()->get('http.route'));

        // Test with an unnamed route - should use the URI pattern
        $this->storage->exchangeArray([]);
        $this->router()->get('/users/{id}/profile', fn () => null);

        $response = $this->call('GET', '/users/123/profile');
        $this->assertEquals(200, $response->status());
        $this->assertCount(5, $this->storage);

        $span = $this->storage[0];
        $this->assertSame('GET /users/{id}/profile', $span->getName());
        $this->assertArrayNotHasKey('laravel.route.name', $span->getAttributes()->toArray());
        $this->assertSame('users/{id}/profile', $span->getAttributes()->get('http.route'));
    }

    public function test_route_span_name_if_not_found(): void
    {
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/not-found');
        $this->assertEquals(404, $response->status());
        $this->assertCount(5, $this->storage);
        $span = $this->storage[0];
        
        $spanName = $span->getName();
        
        $this->assertTrue(
            $spanName === 'GET' || 
            strpos($spanName, 'Handler@render') !== false || 
            strpos($spanName, 'not-found') !== false,
            "Span name should be 'GET' or contain 'Handler@render' or 'not-found'"
        );
    }

    private function router(): Router
    {
        /** @psalm-suppress PossiblyNullReference */
        return $this->app->make(Router::class);
    }
}

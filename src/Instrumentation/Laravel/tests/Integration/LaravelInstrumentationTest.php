<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel;

/** @psalm-suppress UnusedClass */
class LaravelInstrumentationTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    public function test_request_response(): void
    {
        $this->router()->get('/', fn () => Http::get('opentelemetry.io'));

        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/');
        $this->assertEquals(200, $response->status());
        $this->assertCount(4, $this->storage);

        $this->assertTraceStructure([
            [
                'name' => 'GET /',
                'attributes' => [
                    'code.function.name' => 'handle',
                    'code.namespace' => 'Illuminate\Foundation\Http\Kernel',
                    'url.full' => 'http://localhost',
                    'http.request.method' => 'GET',
                    'url.scheme' => 'http',
                    'network.protocol.version' => '1.1',
                    'network.peer.address' => '127.0.0.1',
                    'url.path' => '/',
                    'server.address' => 'localhost',
                    'server.port' => 80,
                    'user_agent.original' => 'Symfony',
                    'http.route' => '/',
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
                                'children' => [
                                    [
                                        'name' => 'HTTP GET',
                                        'attributes' => [
                                            'http.request.method' => 'GET',
                                            'url.full' => 'https://opentelemetry.io/',
                                            'url.path' => '/',
                                            'url.scheme' => 'https',
                                            'server.address' => 'opentelemetry.io',
                                            'server.port' => '',
                                            'http.response.status_code' => 200,
                                            'http.response.body.size' => '21765',
                                        ],
                                        'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_CLIENT,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
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

        $this->printSpans();

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
                                'name' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::handle',
                                'attributes' => [
                                    'laravel.middleware.class' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
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
                                    [
                                        'name' => 'laravel.view.render',
                                        'attributes' => [
                                            'code.function.name' => 'render',
                                            'code.namespace' => 'Illuminate\View\View',
                                            'view.name' => 'welcome',
                                        ],
                                        'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_eloquent_operations(): void
    {
        // Assert storage is empty before interacting with the database
        $this->assertCount(0, $this->storage);

        // Create the test_models table
        DB::statement('CREATE TABLE IF NOT EXISTS test_models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            created_at DATETIME,
            updated_at DATETIME
        )');

        $this->router()->get('/eloquent', function () {
            try {
                // Test create
                $created = TestModel::create(['name' => 'test']);
                
                // Test find
                $found = TestModel::find($created->id);
                
                // Test update
                $found->update(['name' => 'updated']);
                
                // Test delete
                $found->delete();
                
                return response()->json(['status' => 'ok']);
            } catch (\Exception $e) {
                return response()->json([
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ], 500);
            }
        });

        $response = $this->call('GET', '/eloquent');
        if ($response->status() !== 200) {
            $this->fail('Request failed: ' . $response->content());
        }
        $this->assertEquals(200, $response->status());
        
        // Verify spans for each Eloquent operation
        /** @var array<int, \OpenTelemetry\SDK\Trace\ImmutableSpan> $spans */
        $spans = array_values(array_filter(
            iterator_to_array($this->storage),
            fn ($item) => $item instanceof \OpenTelemetry\SDK\Trace\ImmutableSpan
        ));
        
        // Filter out SQL spans and keep only Eloquent spans
        $eloquentSpans = array_values(array_filter(
            $spans,
            fn ($span) => str_contains($span->getName(), '::')
        ));
        
        // Sort spans by operation type to ensure consistent order
        usort($eloquentSpans, function ($a, $b) {
            $operations = ['create' => 0, 'find' => 1, 'update' => 2, 'delete' => 3];
            $aOp = $a->getAttributes()->get('laravel.eloquent.operation');
            $bOp = $b->getAttributes()->get('laravel.eloquent.operation');

            return ($operations[$aOp] ?? 999) <=> ($operations[$bOp] ?? 999);
        });
        
        // Create span
        $createSpan = array_values(array_filter($eloquentSpans, function ($span) {
            return $span->getAttributes()->get('laravel.eloquent.operation') === 'create';
        }))[0];
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel::create', $createSpan->getName());
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel', $createSpan->getAttributes()->get('laravel.eloquent.model'));
        $this->assertSame('test_models', $createSpan->getAttributes()->get('laravel.eloquent.table'));
        $this->assertSame('create', $createSpan->getAttributes()->get('laravel.eloquent.operation'));
        
        // Find span
        $findSpan = array_values(array_filter($eloquentSpans, function ($span) {
            return $span->getAttributes()->get('laravel.eloquent.operation') === 'find';
        }))[0];
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel::find', $findSpan->getName());
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel', $findSpan->getAttributes()->get('laravel.eloquent.model'));
        $this->assertSame('test_models', $findSpan->getAttributes()->get('laravel.eloquent.table'));
        $this->assertSame('find', $findSpan->getAttributes()->get('laravel.eloquent.operation'));
        
        // Update span
        $updateSpan = array_values(array_filter($eloquentSpans, function ($span) {
            return $span->getAttributes()->get('laravel.eloquent.operation') === 'update';
        }))[0];
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel::update', $updateSpan->getName());
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel', $updateSpan->getAttributes()->get('laravel.eloquent.model'));
        $this->assertSame('test_models', $updateSpan->getAttributes()->get('laravel.eloquent.table'));
        $this->assertSame('update', $updateSpan->getAttributes()->get('laravel.eloquent.operation'));
        
        // Delete span
        $deleteSpan = array_values(array_filter($eloquentSpans, function ($span) {
            return $span->getAttributes()->get('laravel.eloquent.operation') === 'delete';
        }))[0];
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel::delete', $deleteSpan->getName());
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel', $deleteSpan->getAttributes()->get('laravel.eloquent.model'));
        $this->assertSame('test_models', $deleteSpan->getAttributes()->get('laravel.eloquent.table'));
        $this->assertSame('delete', $deleteSpan->getAttributes()->get('laravel.eloquent.operation'));
    }

    public function test_low_cardinality_route_span_name(): void
    {
        // Test with a named route - should use the route name
        $this->router()->get('/hello/{name}', fn () => null)->name('hello-name');

        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/hello/opentelemetry');
        $this->assertEquals(200, $response->status());
        $this->assertCount(3, $this->storage);

        $this->assertTraceStructure([
            [
                'name' => 'GET hello-name',
                'attributes' => [
                    'code.function.name' => 'handle',
                    'code.namespace' => 'Illuminate\Foundation\Http\Kernel',
                    'url.full' => 'http://localhost/hello/opentelemetry',
                    'http.request.method' => 'GET',
                    'url.scheme' => 'http',
                    'network.protocol.version' => '1.1',
                    'network.peer.address' => '127.0.0.1',
                    'url.path' => 'hello/opentelemetry',
                    'server.address' => 'localhost',
                    'server.port' => 80,
                    'user_agent.original' => 'Symfony',
                    'http.route' => 'hello/{name}',
                    'laravel.route.name' => 'hello-name',
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

        // Test with an unnamed route - should use the URI pattern
        $this->storage->exchangeArray([]);
        $this->router()->get('/users/{id}/profile', fn () => null);

        $response = $this->call('GET', '/users/123/profile');
        $this->assertEquals(200, $response->status());
        $this->assertCount(3, $this->storage);

        $this->assertTraceStructure([
            [
                'name' => 'GET /users/{id}/profile',
                'attributes' => [
                    'code.function.name' => 'handle',
                    'code.namespace' => 'Illuminate\Foundation\Http\Kernel',
                    'url.full' => 'http://localhost/users/123/profile',
                    'http.request.method' => 'GET',
                    'url.scheme' => 'http',
                    'network.protocol.version' => '1.1',
                    'network.peer.address' => '127.0.0.1',
                    'url.path' => 'users/123/profile',
                    'server.address' => 'localhost',
                    'server.port' => 80,
                    'user_agent.original' => 'Symfony',
                    'http.route' => 'users/{id}/profile',
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

    public function test_route_span_name_if_not_found(): void
    {
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/not-found');
        $this->assertEquals(404, $response->status());
        $this->assertCount(5, $this->storage);

        $this->assertTraceStructure([
            [
                'name' => 'HTTP GET',
                'attributes' => [
                    'code.function.name' => 'handle',
                    'code.namespace' => 'Illuminate\Foundation\Http\Kernel',
                    'url.full' => 'http://localhost/not-found',
                    'http.request.method' => 'GET',
                    'url.scheme' => 'http',
                    'network.protocol.version' => '1.1',
                    'network.peer.address' => '127.0.0.1',
                    'url.path' => 'not-found',
                    'server.address' => 'localhost',
                    'server.port' => 80,
                    'user_agent.original' => 'Symfony',
                    'http.response.status_code' => 404,
                ],
                'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_SERVER,
                'children' => [
                    [
                        'name' => 'Illuminate\Foundation\Http\Middleware\ValidatePostSize::handle',
                        'attributes' => [
                            'laravel.middleware.class' => 'Illuminate\Foundation\Http\Middleware\ValidatePostSize',
                            'http.response.status_code' => 404,
                        ],
                        'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
                        'children' => [
                            [
                                'name' => 'Illuminate\Foundation\Exceptions\Handler@render',
                                'attributes' => [
                                    'code.function.name' => 'handle',
                                    'code.namespace' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
                                    'code.filepath' => '/usr/src/myapp/src/Instrumentation/Laravel/vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/ConvertEmptyStringsToNull.php',
                                    'code.line.number' => 23,
                                    'laravel.middleware.class' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
                                    'http.response.status_code' => 404,
                                ],
                                'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
                                'children' => [
                                    [
                                        'name' => 'laravel.view.render',
                                        'attributes' => [
                                            'code.function.name' => 'render',
                                            'code.namespace' => 'Illuminate\View\View',
                                            'code.filepath' => '/usr/src/myapp/src/Instrumentation/Laravel/vendor/laravel/framework/src/Illuminate/View/View.php',
                                            'code.line.number' => 156,
                                            'view.name' => 'errors::404',
                                        ],
                                        'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
                                        'children' => [
                                            [
                                                'name' => 'laravel.view.render',
                                                'attributes' => [
                                                    'code.function.name' => 'render',
                                                    'code.namespace' => 'Illuminate\View\View',
                                                    'code.filepath' => '/usr/src/myapp/src/Instrumentation/Laravel/vendor/laravel/framework/src/Illuminate/View/View.php',
                                                    'code.line.number' => 156,
                                                    'view.name' => 'errors::minimal',
                                                ],
                                                'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
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

    public function test_controller_execution(): void
    {
        // Define the controller class if it doesn't exist
        if (!class_exists('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestController')) {
            eval('
                namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration;
                
                class TestController
                {
                    public function index()
                    {
                        return "Hello from controller";
                    }
                }
            ');
        }

        $this->router()->get('/controller', [TestController::class, 'index']);

        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/controller');
        $this->assertEquals(200, $response->status());
        $this->assertCount(4, $this->storage);

        $this->assertTraceStructure([
            [
                'name' => 'GET /controller',
                'attributes' => [
                    'code.function.name' => 'handle',
                    'code.namespace' => 'Illuminate\Foundation\Http\Kernel',
                    'url.full' => 'http://localhost/controller',
                    'http.request.method' => 'GET',
                    'url.scheme' => 'http',
                    'network.protocol.version' => '1.1',
                    'network.peer.address' => '127.0.0.1',
                    'url.path' => 'controller',
                    'server.address' => 'localhost',
                    'server.port' => 80,
                    'user_agent.original' => 'Symfony',
                    'http.route' => 'controller',
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
                                'children' => [
                                    [
                                        'name' => 'Controller::index',
                                        'attributes' => [
                                            'code.function.name' => 'index',
                                            'code.namespace' => 'OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestController',
                                        ],
                                        'kind' => \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function router(): Router
    {
        /** @psalm-suppress PossiblyNullReference */
        return $this->app->make(Router::class);
    }
}

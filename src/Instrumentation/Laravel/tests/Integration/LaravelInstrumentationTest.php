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
        $this->router()->get('/', fn () => null);

        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/');
        $this->assertEquals(200, $response->status());
        $this->assertCount(1, $this->storage);
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
        $this->assertCount(3, $this->storage);
        $span = $this->storage[2];
        $this->assertSame('GET /hello', $span->getName());
        $this->assertSame('http://localhost/hello', $span->getAttributes()->get(TraceAttributes::URL_FULL));
        $this->assertCount(4, $span->getEvents());
        $this->assertSame('cache set', $span->getEvents()[0]->getName());
        $this->assertSame('cache miss', $span->getEvents()[1]->getName());
        $this->assertSame('cache hit', $span->getEvents()[2]->getName());
        $this->assertSame('cache forget', $span->getEvents()[3]->getName());

        $span = $this->storage[1];
        $this->assertSame('sql SELECT', $span->getName());
        $this->assertSame('SELECT', $span->getAttributes()->get('db.operation.name'));
        $this->assertSame(':memory:', $span->getAttributes()->get('db.namespace'));
        $this->assertSame('select 1', $span->getAttributes()->get('db.query.text'));
        $this->assertSame('sqlite', $span->getAttributes()->get('db.system.name'));

        /** @var \OpenTelemetry\SDK\Logs\ReadWriteLogRecord $logRecord */
        $logRecord = $this->storage[0];
        $this->assertSame('Log info', $logRecord->getBody());
        $this->assertSame('info', $logRecord->getSeverityText());
        $this->assertSame(9, $logRecord->getSeverityNumber());
        $this->assertArrayHasKey('context', $logRecord->getAttributes()->toArray());
        $this->assertSame(json_encode(['test' => true]), $logRecord->getAttributes()->toArray()['context']);
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
        $this->router()->get('/hello/{name}', fn () => null)->name('hello-name');

        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/hello/opentelemetry');
        $this->assertEquals(200, $response->status());
        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertSame('GET /hello/{name}', $span->getName());
    }

    public function test_route_span_name_if_not_found(): void
    {
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/not-found');
        $this->assertEquals(404, $response->status());
        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertSame('GET', $span->getName());
    }

    private function router(): Router
    {
        /** @psalm-suppress PossiblyNullReference */
        return $this->app->make(Router::class);
    }
}

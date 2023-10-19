<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration;

use ArrayObject;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;

class LaravelInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;

    public function setUp(): void
    {
        parent::setUp();

        $this->storage = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->activate();

        Http::fake();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
        parent::tearDown();
    }

    public function test_request_response(): void
    {
        $this->router()->get('/', fn () => null);

        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/');
        $this->assertEquals(200, $response->status());
        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('HTTP GET', $span->getName());
    
        $response = Http::get('opentelemetry.io');
        $this->assertEquals(200, $response->status());
        $span = $this->storage->offsetGet(1);
        $this->assertSame('HTTP GET', $span->getName());
    }
    public function test_cache_log_db(): void
    {
        $this->router()->get('/hello', function () {
            $text = 'Hello Cruel World';
            cache()->forever('opentelemetry', 'opentelemetry');
            Log::info('Log info');
            cache()->get('opentelemetry.io', 'php');
            cache()->get('opentelemetry', 'php');
            cache()->forget('opentelemetry');
            DB::select('select 1');

            return view('welcome', ['text' => $text]);
        });

        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/hello');
        $this->assertEquals(200, $response->status());
        $this->assertCount(2, $this->storage);
        $span = $this->storage->offsetGet(1);
        $this->assertSame('HTTP GET', $span->getName());
        $this->assertSame('http://localhost/hello', $span->getAttributes()->get(TraceAttributes::URL_FULL));
        $this->assertCount(5, $span->getEvents());
        $this->assertSame('cache set', $span->getEvents()[0]->getName());
        $this->assertSame('Log info', $span->getEvents()[1]->getName());
        $this->assertSame('cache miss', $span->getEvents()[2]->getName());
        $this->assertSame('cache hit', $span->getEvents()[3]->getName());
        $this->assertSame('cache forget', $span->getEvents()[4]->getName());

        $span = $this->storage->offsetGet(0);
        $this->assertSame('sql SELECT', $span->getName());
        $this->assertSame('SELECT', $span->getAttributes()->get('db.operation'));
        $this->assertSame(':memory:', $span->getAttributes()->get('db.name'));
        $this->assertSame('select 1', $span->getAttributes()->get('db.statement'));
        $this->assertSame('sqlite', $span->getAttributes()->get('db.system'));
    }

    private function router(): Router
    {
        /** @psalm-suppress PossiblyNullReference */
        return $this->app->make(Router::class);
    }
}

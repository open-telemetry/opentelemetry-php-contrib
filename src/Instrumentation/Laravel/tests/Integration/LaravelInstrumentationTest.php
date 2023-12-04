<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\SemConv\TraceAttributes;

class LaravelInstrumentationTest extends TestCase
{
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
        $span = $this->storage[1];
        $this->assertSame('GET /hello', $span->getName());
        $this->assertSame('http://localhost/hello', $span->getAttributes()->get(TraceAttributes::URL_FULL));
        $this->assertCount(5, $span->getEvents());
        $this->assertSame('cache set', $span->getEvents()[0]->getName());
        $this->assertSame('Log info', $span->getEvents()[1]->getName());
        $this->assertSame('cache miss', $span->getEvents()[2]->getName());
        $this->assertSame('cache hit', $span->getEvents()[3]->getName());
        $this->assertSame('cache forget', $span->getEvents()[4]->getName());

        $span = $this->storage[0];
        $this->assertSame('sql SELECT', $span->getName());
        $this->assertSame('SELECT', $span->getAttributes()->get('db.operation'));
        $this->assertSame(':memory:', $span->getAttributes()->get('db.name'));
        $this->assertSame('select 1', $span->getAttributes()->get('db.statement'));
        $this->assertSame('sqlite', $span->getAttributes()->get('db.system'));
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

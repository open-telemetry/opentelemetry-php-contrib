<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Unit\Watchers;

use ArrayObject;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context as InstrumentationContext;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ClientRequestWatcher;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WeakMap;

class ClientRequestWatcherTest extends TestCase
{
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;

    protected function setUp(): void
    {
        $this->storage = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage),
            ),
        );
    }

    private function trackedSpans(ClientRequestWatcher $watcher): WeakMap
    {
        $prop = new ReflectionProperty(ClientRequestWatcher::class, 'spans');
        $prop->setAccessible(true);

        return $prop->getValue($watcher);
    }

    public function test_it_matches_the_correct_span_when_responses_resolve_out_of_order(): void
    {
        $watcher = new ClientRequestWatcher(new InstrumentationContext(tracerProvider: $this->tracerProvider));

        $psr7RequestA = new Psr7Request('GET', 'http://same.opentelemetry.io');
        $psr7RequestB = new Psr7Request('GET', 'http://same.opentelemetry.io');

        $requestA = new Request($psr7RequestA);
        $requestB = new Request($psr7RequestB);

        $watcher->recordRequest(new RequestSending($requestA));
        $watcher->recordRequest(new RequestSending($requestB));

        $this->assertTrue($this->trackedSpans($watcher)->offsetExists($psr7RequestA));
        $this->assertTrue($this->trackedSpans($watcher)->offsetExists($psr7RequestB));

        $watcher->recordResponse(new ResponseReceived($requestB, new Response(new Psr7Response(500))));

        $remaining = $this->trackedSpans($watcher);
        $this->assertFalse($remaining->offsetExists($psr7RequestB));
        $this->assertTrue($remaining->offsetExists($psr7RequestA));

        $watcher->recordResponse(new ResponseReceived($requestA, new Response(new Psr7Response(200))));

        $this->assertCount(0, $this->trackedSpans($watcher));

        $this->assertCount(2, $this->storage);
        $this->assertEquals(500, $this->storage[0]->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertEquals(200, $this->storage[1]->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public function test_it_drops_the_span_of_a_request_that_is_released_without_a_response(): void
    {
        $watcher = new ClientRequestWatcher(new InstrumentationContext(tracerProvider: $this->tracerProvider));

        // Neither ResponseReceived nor ConnectionFailed is dispatched for this request, so its span
        // must not stay tracked once the request itself is gone.
        $watcher->recordRequest(new RequestSending(new Request(new Psr7Request('GET', 'http://released.opentelemetry.io'))));

        $this->assertCount(0, $this->trackedSpans($watcher));
    }
}

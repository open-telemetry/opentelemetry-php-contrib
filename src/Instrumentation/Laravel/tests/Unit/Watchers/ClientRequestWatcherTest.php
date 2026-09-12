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
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ClientRequestWatcher;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class ClientRequestWatcherTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;

    protected function setUp(): void
    {
        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage),
            ),
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->activate();
    }

    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    private function trackedSpans(ClientRequestWatcher $watcher): array
    {
        $prop = new ReflectionProperty(ClientRequestWatcher::class, 'spans');
        $prop->setAccessible(true);

        return $prop->getValue($watcher);
    }

    public function test_it_matches_the_correct_span_when_responses_resolve_out_of_order(): void
    {
        $watcher = new ClientRequestWatcher(new CachedInstrumentation('io.opentelemetry.contrib.php.laravel'));

        $psr7RequestA = new Psr7Request('GET', 'http://same.opentelemetry.io');
        $psr7RequestB = new Psr7Request('GET', 'http://same.opentelemetry.io');

        $requestA = new Request($psr7RequestA);
        $requestB = new Request($psr7RequestB);

        $watcher->recordRequest(new RequestSending($requestA));
        $watcher->recordRequest(new RequestSending($requestB));

        $idA = spl_object_id($psr7RequestA);
        $idB = spl_object_id($psr7RequestB);

        $this->assertArrayHasKey($idA, $this->trackedSpans($watcher));
        $this->assertArrayHasKey($idB, $this->trackedSpans($watcher));

        $watcher->recordResponse(new ResponseReceived($requestB, new Response(new Psr7Response(500))));

        $remaining = $this->trackedSpans($watcher);
        $this->assertArrayNotHasKey($idB, $remaining);
        $this->assertArrayHasKey($idA, $remaining);

        $watcher->recordResponse(new ResponseReceived($requestA, new Response(new Psr7Response(200))));

        $this->assertSame([], $this->trackedSpans($watcher));

        $this->assertCount(2, $this->storage);
        $this->assertEquals(500, $this->storage[0]->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertEquals(200, $this->storage[1]->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }
}

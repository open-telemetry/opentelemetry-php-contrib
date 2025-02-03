<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Curl\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;

class CurlInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<int, ImmutableSpan> */
    private ArrayObject $storage;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->withPropagator(TraceContextPropagator::getInstance())
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_curl_reset(): void
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_URL, 'http://gugugaga.gugugaga/');
        curl_reset($ch);
        curl_exec($ch);

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('GET', $span->getName());
        $this->assertSame('Error', $span->getStatus()->getCode());
        $this->assertSame('URL using bad/illegal format or missing URL (3)', $span->getStatus()->getDescription());
    }

    public function test_curl_setopt(): void
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_URL, 'http://gugugaga.gugugaga/');
        curl_exec($ch);

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('http://gugugaga.gugugaga/', $span->getAttributes()->get(TraceAttributes::URL_FULL));
        $this->assertSame('POST', $span->getName());
        $this->assertSame('Error', $span->getStatus()->getCode());
        $this->assertStringContainsString('resolve host', $span->getStatus()->getDescription());
    }

    public function test_curl_setopt_overrides_url(): void
    {
        $ch = curl_init('http://example.com');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_URL, 'http://gugugaga.gugugaga/');
        curl_exec($ch);

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('http://gugugaga.gugugaga/', $span->getAttributes()->get(TraceAttributes::URL_FULL));
    }

    public function test_curl_setopt_array(): void
    {
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_POST => 1,  CURLOPT_URL => 'http://gugugaga.gugugaga/']);
        curl_exec($ch);

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('POST', $span->getName());
        $this->assertSame('Error', $span->getStatus()->getCode());
        $this->assertStringContainsString('resolve host', $span->getStatus()->getDescription());
    }

    public function test_curl_setopt_array_partial_success(): void
    {
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_POST => 1,  CURLOPT_URL => 'http://gugugaga.gugugaga/', CURLOPT_SSLVERSION => 1000 ]);
        curl_exec($ch);

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('POST', $span->getName());
        $this->assertSame('Error', $span->getStatus()->getCode());
        $this->assertStringContainsString('resolve host', $span->getStatus()->getDescription());
    }

    public function test_curl_copy_handle(): void
    {
        $ch = curl_init('http://gugugaga.gugugaga/');
        curl_setopt($ch, CURLOPT_POST, 1);

        $ch_copy = curl_copy_handle($ch);
        curl_close($ch);

        curl_exec($ch_copy);

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('POST', $span->getName());
        $this->assertSame('Error', $span->getStatus()->getCode());
        $this->assertStringContainsString('resolve host', $span->getStatus()->getDescription());
    }

    public function test_curl_exec_with_error(): void
    {
        $ch = curl_init('http://gugugaga.gugugaga/');
        curl_exec($ch);

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('GET', $span->getName());
        $this->assertSame('Error', $span->getStatus()->getCode());
        $this->assertStringContainsString('resolve host', $span->getStatus()->getDescription());
        $this->assertEquals('cURL error (6)', $span->getAttributes()->get(TraceAttributes::ERROR_TYPE));
        $this->assertEquals('GET', $span->getAttributes()->get(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertEquals('http://gugugaga.gugugaga/', $span->getAttributes()->get(TraceAttributes::URL_FULL));
    }

    public function test_curl_exec(): void
    {
        $ch = curl_init('http://example.com/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_exec($ch);

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('GET', $span->getName());
        $this->assertEquals(200, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertEqualsIgnoringCase('http', $span->getAttributes()->get(TraceAttributes::URL_SCHEME));
        $this->assertEquals(80, $span->getAttributes()->get(TraceAttributes::SERVER_PORT));
    }

    public function test_curl_exec_calls_user_defined_headerfunc(): void
    {
        // test if response header capturing is not breaking user header func invocation

        putenv('OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS=server');
        putenv('OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS=host');

        $ch = curl_init('http://example.com/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $func = function (\CurlHandle $ch, string $headerLine) {
            return strlen($headerLine);
        };

        $mockedFunc = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['__invoke'])
            ->getMock();

        $mockedFunc->expects($this->atLeastOnce())
            ->method('__invoke')
            ->willReturnCallback($func);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, $mockedFunc);
        curl_exec($ch);

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('GET', $span->getName());
        $this->assertEquals(200, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertEqualsIgnoringCase('http', $span->getAttributes()->get(TraceAttributes::URL_SCHEME));
        $this->assertEquals(80, $span->getAttributes()->get(TraceAttributes::SERVER_PORT));
    }

    public function test_curl_exec_headers_capturing(): void
    {
        putenv('OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS=content-type');
        putenv('OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS=host');

        $ch = curl_init('http://example.com/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        curl_exec($ch);

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('GET', $span->getName());
        $this->assertEquals(200, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertEqualsIgnoringCase('http', $span->getAttributes()->get(TraceAttributes::URL_SCHEME));
        $this->assertStringContainsStringIgnoringCase('text/html', $span->getAttributes()->get('http.response.header.content-type'));
        $this->assertEquals('example.com', $span->getAttributes()->get('http.request.header.host'));
    }

    public function test_curl_exec_sets_traceparent(): void
    {
        putenv('OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS=traceparent');

        $ch = curl_init('http://example.com/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        curl_exec($ch);

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('GET', $span->getName());
        $this->assertEquals(200, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertEqualsIgnoringCase('http', $span->getAttributes()->get(TraceAttributes::URL_SCHEME));
        $this->assertNotEmpty($span->getAttributes()->get('http.request.header.traceparent'));
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Curl\Integration;

use ArrayObject;
use CurlHandle;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;

class CurlMultiInstrumentationTest extends TestCase
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

    public function test_curl_multi()
    {
        $mh = curl_multi_init();
        $ch1 = curl_init('http://example.com/');
        $this->assertInstanceOf(CurlHandle::class, $ch1);
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, 1);

        $ch2 = curl_copy_handle($ch1);
        $this->assertInstanceOf(CurlHandle::class, $ch2);

        curl_multi_add_handle($mh, $ch1);
        curl_multi_add_handle($mh, $ch2);

        $running = null;
        do {
            curl_multi_exec($mh, $running);

            while (($info = curl_multi_info_read($mh)) !== false) {
            }
        } while ($running);

        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);
        curl_multi_close($mh);

        $this->assertCount(2, $this->storage);
        foreach ([0, 1] as $offset) {
            $span = $this->storage->offsetGet($offset);
            $this->assertSame('GET', $span->getName());
            $this->assertEquals(200, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
            $this->assertEqualsIgnoringCase('http', $span->getAttributes()->get(TraceAttributes::URL_SCHEME));
            $this->assertEquals(80, $span->getAttributes()->get(TraceAttributes::SERVER_PORT));
        }
    }

    public function test_curl_multi_error()
    {
        $mh = curl_multi_init();
        $ch1 = curl_init('unknown://scheme.com/');
        $this->assertInstanceOf(CurlHandle::class, $ch1);

        curl_multi_add_handle($mh, $ch1);

        $running = null;
        do {
            curl_multi_exec($mh, $running);

            while (($info = curl_multi_info_read($mh)) !== false) {
            }
        } while ($running);

        curl_multi_close($mh);

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('curl_multi_exec', $span->getAttributes()->get(TraceAttributes::CODE_FUNCTION_NAME));
        $this->assertEquals('unknown://scheme.com/', actual: $span->getAttributes()->get(TraceAttributes::URL_FULL));
        $this->assertSame('GET', $span->getName());
    }

    public function test_curl_multi_remove_handle()
    {
        $mh = curl_multi_init();
        $ch1 = curl_init('unknown://scheme.com/');
        $ch2 = curl_init('other://scheme.com/');
        $this->assertInstanceOf(CurlHandle::class, $ch1);
        $this->assertInstanceOf(CurlHandle::class, $ch2);

        curl_multi_add_handle($mh, $ch1);
        curl_multi_add_handle($mh, $ch2);

        curl_multi_remove_handle($mh, $ch1);

        $running = null;
        do {
            curl_multi_exec($mh, $running);

            while (($info = curl_multi_info_read($mh)) !== false) {
            }
        } while ($running);

        curl_multi_close($mh);

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('other://scheme.com/', actual: $span->getAttributes()->get(TraceAttributes::URL_FULL));
    }

    public function test_curl_multi_exec_calls_user_defined_headerfunc(): void
    {
        putenv('OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS=content-type');
        putenv('OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS=host');

        $mh = curl_multi_init();
        $ch1 = curl_init('http://example.com/');
        $this->assertInstanceOf(CurlHandle::class, $ch1);
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, 1);

        $func = function (\CurlHandle $ch, string $headerLine) {
            return strlen($headerLine);
        };

        $mockedFunc = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['__invoke'])
            ->getMock();

        $mockedFunc->expects($this->atLeastOnce())
            ->method('__invoke')
            ->willReturnCallback($func);

        curl_setopt($ch1, CURLOPT_HEADERFUNCTION, $mockedFunc);

        $ch2 = curl_copy_handle($ch1);
        $this->assertInstanceOf(CurlHandle::class, $ch2);

        curl_multi_add_handle($mh, $ch1);
        curl_multi_add_handle($mh, $ch2);

        $running = null;
        do {
            curl_multi_exec($mh, $running);

            while (($info = curl_multi_info_read($mh)) !== false) {
            }
        } while ($running);

        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);
        curl_multi_close($mh);

        $this->assertCount(2, $this->storage);
        foreach ([0, 1] as $offset) {
            $span = $this->storage->offsetGet($offset);
            $this->assertSame('GET', $span->getName());
            $this->assertEquals(200, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
            $this->assertEqualsIgnoringCase('http', $span->getAttributes()->get(TraceAttributes::URL_SCHEME));
            $this->assertEquals(80, $span->getAttributes()->get(TraceAttributes::SERVER_PORT));
        }
    }

    public function test_curl_multi_exec_headers_capturing(): void
    {
        putenv('OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS=content-type');
        putenv('OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS=host');

        $mh = curl_multi_init();
        $ch1 = curl_init('http://example.com/');
        $this->assertInstanceOf(CurlHandle::class, $ch1);
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, 1);

        $ch2 = curl_copy_handle($ch1);
        $this->assertInstanceOf(CurlHandle::class, $ch2);

        curl_multi_add_handle($mh, $ch1);
        curl_multi_add_handle($mh, $ch2);

        $running = null;
        do {
            curl_multi_exec($mh, $running);

            while (($info = curl_multi_info_read($mh)) !== false) {
            }
        } while ($running);

        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);
        curl_multi_close($mh);

        $this->assertCount(2, $this->storage);
        foreach ([0, 1] as $offset) {
            $span = $this->storage->offsetGet($offset);
            $this->assertSame('GET', $span->getName());
            $this->assertEquals(200, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
            $this->assertEqualsIgnoringCase('http', $span->getAttributes()->get(TraceAttributes::URL_SCHEME));
            $this->assertEquals(80, $span->getAttributes()->get(TraceAttributes::SERVER_PORT));
            $this->assertStringContainsStringIgnoringCase('text/html', $span->getAttributes()->get('http.response.header.content-type'));
            $this->assertEquals('example.com', $span->getAttributes()->get('http.request.header.host'));
        }
    }

    public function test_curl_multi_exec_sets_traceparent(): void
    {
        putenv('OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS=traceparent');

        $mh = curl_multi_init();
        $ch1 = curl_init('http://example.com/');
        $this->assertInstanceOf(CurlHandle::class, $ch1);
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, 1);

        $ch2 = curl_copy_handle($ch1);
        $this->assertInstanceOf(CurlHandle::class, $ch2);

        curl_multi_add_handle($mh, $ch1);
        curl_multi_add_handle($mh, $ch2);

        $running = null;
        do {
            curl_multi_exec($mh, $running);

            while (($info = curl_multi_info_read($mh)) !== false) {
            }
        } while ($running);

        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);
        curl_multi_close($mh);

        $this->assertCount(2, $this->storage);
        foreach ([0, 1] as $offset) {
            $span = $this->storage->offsetGet($offset);
            $this->assertSame('GET', $span->getName());
            $this->assertEquals(200, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
            $this->assertEqualsIgnoringCase('http', $span->getAttributes()->get(TraceAttributes::URL_SCHEME));
            $this->assertEquals(80, $span->getAttributes()->get(TraceAttributes::SERVER_PORT));
            $this->assertNotEmpty($span->getAttributes()->get('http.request.header.traceparent'));
        }
    }
}

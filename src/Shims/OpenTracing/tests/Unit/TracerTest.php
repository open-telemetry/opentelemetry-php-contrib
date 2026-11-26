<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Shim\OpenTracing\Unit;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Shim\OpenTracing\Tracer;
use OpenTracing as API;
use PHPUnit\Framework\TestCase;

class TracerTest extends TestCase
{
    private Tracer $tracer;
    private TracerProviderInterface $tracerProvider;

    #[\Override]
    protected function setUp(): void
    {
        $this->tracerProvider = $this->createMock(TracerProviderInterface::class);
        $otelTracer = $this->createMock(TracerInterface::class);
        $this->tracerProvider->method('getTracer')->willReturn($otelTracer);
        $this->tracer = new Tracer($this->tracerProvider);
    }

    public function test_extract_with_unsupported_format_returns_null(): void
    {
        $result = $this->tracer->extract('unsupported_format', []);
        $this->assertNull($result);
    }

    public function test_extract_with_binary_format_throws(): void
    {
        $this->expectException(API\UnsupportedFormatException::class);
        $this->tracer->extract(API\Formats\BINARY, []);
    }

    public function test_inject_with_binary_format_throws(): void
    {
        $this->expectException(API\UnsupportedFormatException::class);
        $context = $this->createMock(\OpenTelemetry\Context\ContextInterface::class);
        $spanContext = new \OpenTelemetry\Contrib\Shim\OpenTracing\SpanContext($context);
        $carrier = [];
        $this->tracer->inject($spanContext, API\Formats\BINARY, $carrier);
    }

    public function test_flush_with_non_sdk_provider(): void
    {
        $this->tracer->flush();
        $this->assertTrue(true); // No exception means success
    }

    public function test_flush_with_sdk_provider(): void
    {
        $sdkProvider = $this->createMock(\OpenTelemetry\SDK\Trace\TracerProviderInterface::class);
        $otelTracer = $this->createMock(TracerInterface::class);
        $sdkProvider->method('getTracer')->willReturn($otelTracer);
        $sdkProvider->expects($this->once())->method('forceFlush')->willReturn(true);

        $tracer = new Tracer($sdkProvider);
        $tracer->flush();
    }
}

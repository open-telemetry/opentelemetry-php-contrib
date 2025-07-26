<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Aws\Unit\Xray;

use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Aws\Xray\Propagator;
use OpenTelemetry\SDK\Trace\Span;
use PHPUnit\Framework\TestCase;

class PropagatorTest extends TestCase
{
    private const TRACE_ID = '5759e988bd862e3fe1be46a994272793';
    private const SPAN_ID = '53995c3f42cd8ad8';
    private const IS_SAMPLED = '1';
    private const NOT_SAMPLED = '0';
    private const TRACE_ID_HEADER_SAMPLED = 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1';
    private const TRACE_ID_HEADER_NOT_SAMPLED = 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=0';

    /**
     * @test
     * fields() should return an array of fields with AWS X-Ray Trace ID Header
     */
    public function TestFields()
    {
        $propagator = new Propagator();
        $this->assertSame($propagator->fields(), [Propagator::AWSXRAY_TRACE_ID_HEADER]);
    }

    /**
     * @test
     * Injects with a valid traceId, spanId, and is sampled
     * restore(string $traceId, string $spanId, bool $sampled = false, bool $isRemote = false, ?API\TraceState $traceState = null): SpanContext
     */
    public function InjectValidSampledTraceId()
    {
        $carrier = [];
        (new Propagator())->inject(
            $carrier,
            null,
            $this->withSpanContext(
                SpanContext::create(self::TRACE_ID, self::SPAN_ID, TraceFlags::SAMPLED),
                Context::getCurrent()
            )
        );

        $this->assertSame(
            [Propagator::AWSXRAY_TRACE_ID_HEADER => self::TRACE_ID_HEADER_SAMPLED],
            $carrier
        );
    }

    /**
     * @test
     * Injects with a valid traceId, spanId, and is not sampled
     */
    public function InjectValidNotSampledTraceId()
    {
        $carrier = [];
        (new Propagator())->inject(
            $carrier,
            null,
            $this->withSpanContext(
                SpanContext::create(self::TRACE_ID, self::SPAN_ID),
                Context::getCurrent()
            )
        );

        $this->assertSame(
            [Propagator::AWSXRAY_TRACE_ID_HEADER => self::TRACE_ID_HEADER_NOT_SAMPLED],
            $carrier
        );
    }

    /**
     * @test
     * Test inject with tracestate
     */
    public function InjectTraceIdWithTraceState()
    {
        $carrier = [];
        (new Propagator())->inject(
            $carrier,
            null,
            $this->withSpanContext(
                SpanContext::create(self::TRACE_ID, self::SPAN_ID, TraceFlags::SAMPLED, new TraceState('vendor1=opaqueValue1')),
                Context::getCurrent()
            )
        );

        $this->assertSame(
            [Propagator::AWSXRAY_TRACE_ID_HEADER => self::TRACE_ID_HEADER_SAMPLED],
            $carrier
        );
    }

    /**
     * @test
     * Test with an invalid spanContext, should return null
     */
    public function InjectTraceIdWithInvalidSpanContext()
    {
        $carrier = [];
        (new Propagator())->inject(
            $carrier,
            null,
            $this->withSpanContext(
                SpanContext::create(SpanContextValidator::INVALID_TRACE, SpanContextValidator::INVALID_SPAN, TraceFlags::SAMPLED, new TraceState('vendor1=opaqueValue1')),
                Context::getCurrent()
            )
        );

        $this->assertEmpty($carrier);
    }

    /**
     * @test
     * Test sampled, not sampled, extra fields, arbitrary order
     */
    public function ExtractValidSampledContext()
    {
        $traceHeaders = ['Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1',
        'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=0',
        'Root=1-5759e988-bd862e3fe1be46a994272793;Foo=Bar;Parent=53995c3f42cd8ad8;Sampled=0', ];

        foreach ($traceHeaders as $traceHeader) {
            $carrier = [Propagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
            $context = Span::fromContext((new Propagator())->extract($carrier))->getContext();

            $this->assertSame(self::TRACE_ID, $context->getTraceId());
            $this->assertSame(self::SPAN_ID, $context->getSpanId());
            $this->assertSame(substr($traceHeader, -1), ($context->isSampled() ? '1' : '0'));
            $this->assertTrue($context->isRemote());
        }
    }

    /**
     * @test
     * Test arbitrary order
     */
    public function ExtractValidSampledContextAbitraryOrder()
    {
        $traceHeader = 'Parent=53995c3f42cd8ad8;Sampled=1;Root=1-5759e988-bd862e3fe1be46a994272793';

        $carrier = [Propagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $context = Span::fromContext((new Propagator())->extract($carrier))->getContext();

        $this->assertSame(self::TRACE_ID, $context->getTraceId());
        $this->assertSame(self::SPAN_ID, $context->getSpanId());
        $this->assertSame(self::IS_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertTrue($context->isRemote());
    }

    /**
     * @test
     * Must have '-' and not other delimiters
     */
    public function ExtractInvalidTraceIdDelimiter()
    {
        $traceHeader = 'Root=1*5759e988*bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1';

        $carrier = [Propagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $context = Span::fromContext((new Propagator())->extract($carrier))->getContext();

        $this->assertSame(SpanContextValidator::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContextValidator::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Should return invalid spanContext
     */
    public function ExtractEmptySpanContext()
    {
        $traceHeader = '';

        $carrier = [Propagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $context = Span::fromContext((new Propagator())->extract($carrier))->getContext();

        $this->assertSame(SpanContextValidator::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContextValidator::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Test different invalidSpanContexts
     */
    public function ExtractInvalidSpanContext()
    {
        $traceHeaders = [' ', 'abc-def-hig', '123abc'];

        foreach ($traceHeaders as $traceHeader) {
            $carrier = [Propagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
            $context = Span::fromContext((new Propagator())->extract($carrier))->getContext();

            $this->assertSame(SpanContextValidator::INVALID_TRACE, $context->getTraceId());
            $this->assertSame(SpanContextValidator::INVALID_SPAN, $context->getSpanId());
            $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
            $this->assertFalse($context->isRemote());
        }
    }

    /**
     * @test
     * Test Invalid Trace Id
     */
    public function ExtractInvalidTraceId()
    {
        $traceHeader = 'Root=1-00000000-000000000000000000000000;Parent=53995c3f42cd8ad8;Sampled=1';

        $carrier = [Propagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $context = Span::fromContext((new Propagator())->extract($carrier))->getContext();

        $this->assertSame(SpanContextValidator::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContextValidator::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Test Invalid Trace Id Length
     */
    public function ExtractInvalidTraceIdLength()
    {
        $traceHeader = 'Root=1-5759e98s46v8-bd862e3fe1frbe46a994272793;Parent=53995c3f42cd8ad8;Sampled=1';

        $carrier = [Propagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $context = Span::fromContext((new Propagator())->extract($carrier))->getContext();

        $this->assertSame(SpanContextValidator::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContextValidator::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Test Invalid Span Id
     */
    public function ExtractInvalidSpanId()
    {
        $traceHeader = 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=0000000000000000;Sampled=1';

        $carrier = [Propagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $context = Span::fromContext((new Propagator())->extract($carrier))->getContext();

        $this->assertSame(SpanContextValidator::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContextValidator::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Test Invalid Span Id
     */
    public function ExtractInvalidSpanIdLength()
    {
        $traceHeader = 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad85dg;Sampled=1';

        $carrier = [Propagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $context = Span::fromContext((new Propagator())->extract($carrier))->getContext();

        $this->assertSame(SpanContextValidator::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContextValidator::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Test null sample value
     */
    public function ExtractNullSampledValue()
    {
        $traceHeader = 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=';

        $carrier = [Propagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $context = Span::fromContext((new Propagator())->extract($carrier))->getContext();

        $this->assertSame(SpanContextValidator::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContextValidator::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Test invalid sample value
     */
    public function ExtractInvalidSampleValue()
    {
        $traceHeader = 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=12345';

        $carrier = [Propagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $context = Span::fromContext((new Propagator())->extract($carrier))->getContext();

        $this->assertSame(SpanContextValidator::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContextValidator::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    /**
     * @test
     * Test incorrect xray version
     */
    public function ExtractInvalidXrayVersion()
    {
        $traceHeader = 'Root=2-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=12345';

        $carrier = [Propagator::AWSXRAY_TRACE_ID_HEADER => $traceHeader];
        $context = Span::fromContext((new Propagator())->extract($carrier))->getContext();

        $this->assertSame(SpanContextValidator::INVALID_TRACE, $context->getTraceId());
        $this->assertSame(SpanContextValidator::INVALID_SPAN, $context->getSpanId());
        $this->assertSame(self::NOT_SAMPLED, ($context->isSampled() ? '1' : '0'));
        $this->assertFalse($context->isRemote());
    }

    private function withSpanContext(SpanContextInterface $spanContext, ContextInterface $context): ContextInterface
    {
        return $context->withContextValue(Span::wrap($spanContext));
    }
}

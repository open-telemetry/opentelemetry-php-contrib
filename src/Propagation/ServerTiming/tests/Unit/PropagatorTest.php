<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Propagation\TraceResponse\Unit;

use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Propagation\ServerTiming\ServerTimingPropagator as Propagator;
use OpenTelemetry\SDK\Trace\Span;
use PHPUnit\Framework\TestCase;

class PropagatorTest extends TestCase
{
    private const TRACE_ID = '5759e988bd862e3fe1be46a994272793';
    private const SPAN_ID = '53995c3f42cd8ad8';
    private const TRACERESPONSE_HEADER_SAMPLED = '00-5759e988bd862e3fe1be46a994272793-53995c3f42cd8ad8-01';
    private const TRACERESPONSE_HEADER_NOT_SAMPLED = '00-5759e988bd862e3fe1be46a994272793-53995c3f42cd8ad8-00';

    /**
     * @test
     * fields() should return an array of fields that will be set on the carrier
     */
    public function test_fields()
    {
        $propagator = new Propagator();
        $this->assertSame($propagator->fields(), [Propagator::SERVER_TIMING]);
    }

    /**
     * @test
     * Injects with a valid traceId, spanId, and is sampled
     * restore(string $traceId, string $spanId, bool $sampled = false, bool $isRemote = false, ?API\TraceState $traceState = null): SpanContext
     */
    public function test_inject_valid_sampled_trace_id()
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
            [Propagator::SERVER_TIMING => sprintf('%s;desc=%s', Propagator::TRACEPARENT, self::TRACERESPONSE_HEADER_SAMPLED)],
            $carrier
        );
    }

    /**
     * @test
     * Injects with a valid traceId, spanId, and is not sampled
     */
    public function test_inject_valid_not_sampled_trace_id()
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
            [Propagator::SERVER_TIMING => sprintf('%s;desc=%s', Propagator::TRACEPARENT, self::TRACERESPONSE_HEADER_NOT_SAMPLED)],
            $carrier
        );
    }

    /**
     * @test
     * Test inject with tracestate - note: tracestate is not a part of traceresponse
     */
    public function test_inject_trace_id_with_trace_state()
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
            [Propagator::SERVER_TIMING => sprintf('%s;desc=%s', Propagator::TRACEPARENT, self::TRACERESPONSE_HEADER_SAMPLED)],
            $carrier
        );
    }

    /**
     * @test
     * Test with an invalid spanContext, should return null
     */
    public function test_inject_trace_id_with_invalid_span_context()
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

    private function withSpanContext(SpanContextInterface $spanContext, ContextInterface $context): ContextInterface
    {
        return $context->withContextValue(Span::wrap($spanContext));
    }
}

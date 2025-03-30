<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Unit\Propagation;

use OpenTelemetry\Contrib\Instrumentation\Symfony\Propagation\EnvelopeContextPropagator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\SerializerStamp;

final class EnvelopeContextPropagatorTest extends TestCase
{
    private EnvelopeContextPropagator $propagator;
    private TestMessage $message;
    private Envelope $envelope;

    protected function setUp(): void
    {
        $this->propagator = EnvelopeContextPropagator::getInstance();
        $this->message = new TestMessage('test');
        $this->envelope = new Envelope($this->message);
    }

    public function test_singleton_instance(): void
    {
        $instance1 = EnvelopeContextPropagator::getInstance();
        $instance2 = EnvelopeContextPropagator::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function test_inject_context_into_envelope(): void
    {
        $context = [
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            'tracestate' => 'congo=t61rcWkgMzE',
        ];

        $envelopeWithContext = $this->propagator->injectContextIntoEnvelope($this->envelope, $context);
        
        /** @var SerializerStamp $stamp */
        $stamp = $envelopeWithContext->last(SerializerStamp::class);
        $this->assertNotNull($stamp);
        
        $stampContext = $stamp->getContext();
        $this->assertArrayHasKey('otel_context', $stampContext);
        $this->assertSame($context, $stampContext['otel_context']);
    }

    public function test_inject_empty_context_returns_original_envelope(): void
    {
        $envelopeWithContext = $this->propagator->injectContextIntoEnvelope($this->envelope, []);
        
        $this->assertSame($this->envelope, $envelopeWithContext);
        $this->assertNull($envelopeWithContext->last(SerializerStamp::class));
    }

    public function test_extract_context_from_envelope(): void
    {
        $context = [
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            'tracestate' => 'congo=t61rcWkgMzE',
        ];

        $envelopeWithContext = $this->propagator->injectContextIntoEnvelope($this->envelope, $context);
        $extractedContext = $this->propagator->extractContextFromEnvelope($envelopeWithContext);

        $this->assertSame($context, $extractedContext);
    }

    public function test_extract_context_from_envelope_without_context(): void
    {
        $extractedContext = $this->propagator->extractContextFromEnvelope($this->envelope);

        $this->assertNull($extractedContext);
    }

    public function test_extract_context_from_envelope_with_multiple_serializer_stamps(): void
    {
        $context = [
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ];

        // Add a serializer stamp without context
        $envelope = $this->envelope->with(new SerializerStamp(['foo' => 'bar']));
        // Add a serializer stamp with context
        $envelope = $this->propagator->injectContextIntoEnvelope($envelope, $context);

        $extractedContext = $this->propagator->extractContextFromEnvelope($envelope);

        $this->assertSame($context, $extractedContext);
    }

    public function test_propagator_getter_interface(): void
    {
        $carrier = [
            'foo' => 'bar',
            'baz' => 'qux',
        ];

        $this->assertSame(['foo', 'baz'], $this->propagator->keys($carrier));
        $this->assertSame('bar', $this->propagator->get($carrier, 'foo'));
        $this->assertNull($this->propagator->get($carrier, 'nonexistent'));
    }

    public function test_propagator_setter_interface(): void
    {
        $carrier = [];
        $this->propagator->set($carrier, 'foo', 'bar');

        $this->assertSame(['foo' => 'bar'], $carrier);
    }
}

/**
 * Simple message class for testing
 */
final class TestMessage
{
    public function __construct(private string $content)
    {
    }

    public function getContent(): string
    {
        return $this->content;
    }
} 
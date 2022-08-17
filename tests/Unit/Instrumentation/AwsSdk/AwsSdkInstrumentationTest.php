<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Instrumentation\AwsSdk;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Aws\Xray\IdGenerator;
use OpenTelemetry\Aws\Xray\Propagator;
use OpenTelemetry\Instrumentation\AwsSdk\AwsSdkInstrumentation;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

class AwsSdkInstrumentationTest extends TestCase
{
    private TracerProvider $tracerProvider;
    private AwsSdkInstrumentation $awsSdkInstrumentation;

    protected function setUp(): void
    {
        $spanProcessor = new SimpleSpanProcessor(new ConsoleSpanExporter());
        $this->tracerProvider = new TracerProvider([$spanProcessor], null, null, null, new IdGenerator());

        $this->awsSdkInstrumentation = new AwsSdkInstrumentation();
        $this->awsSdkInstrumentation->setTracerProvider($this->tracerProvider);
    }

    public function testInstrumentationClassName()
    {
        $this->assertEquals(
            'AWS SDK Instrumentation',
            (new AwsSdkInstrumentation())->getName()
        );
    }

    public function testInstrumentationVersion()
    {
        $this->assertEquals(
            '0.0.1',
            (new AwsSdkInstrumentation())->getVersion()
        );
    }

    public function testInstrumentationSchemaUrl()
    {
        $this->assertNull((new AwsSdkInstrumentation())->getSchemaUrl());
    }

    public function testInstrumentationInit()
    {
        $this->assertTrue(
            (new AwsSdkInstrumentation())->init()
        );
    }

    public function testSetXrayPropagator()
    {
        $this->assertInstanceOf(
            Propagator::class,
            new Propagator()
        );
    }

    public function testGetXrayPropagator()
    {
        $propagator = new Propagator();
        $this->awsSdkInstrumentation->setPropagator($propagator);

        $this->assertSame(
            $this->awsSdkInstrumentation->getPropagator(),
            $propagator
        );
    }

    public function testSetTracerProvider()
    {
        $this->awsSdkInstrumentation->setTracerProvider($this->tracerProvider);

        $this->assertInstanceOf(
            TracerProvider::class,
            $this->tracerProvider
        );
    }

    public function testGetTracerProvider()
    {
        $this->awsSdkInstrumentation->setTracerProvider($this->tracerProvider);

        $this->assertSame(
            $this->awsSdkInstrumentation->getTracerProvider(),
            $this->tracerProvider
        );
    }

    public function testGetTracer()
    {
        $this->awsSdkInstrumentation->setTracerProvider($this->tracerProvider);

        $this->assertInstanceOf(
            TracerInterface::class,
            $this->awsSdkInstrumentation->getTracer()
        );
    }

    public function testInstrumentationActivated()
    {
        $this->assertTrue(
            (new AwsSdkInstrumentation())->activate()
        );
    }
}

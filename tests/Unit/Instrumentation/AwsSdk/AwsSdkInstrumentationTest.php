<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Instrumentation\AwsSdk;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Aws\Xray\IdGenerator;
use OpenTelemetry\Aws\Xray\Propagator;
use OpenTelemetry\Instrumentation\AwsSdk\AwsSdkInstrumentation;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\TestCase;
use DG\BypassFinals;

class AwsSdkInstrumentationTest extends TestCase
{
    private TracerProvider $tracerProvider;
    private AwsSdkInstrumentation $awsSdkInstrumentation;

    protected function setUp(): void
    {
        BypassFinals::enable();
        $this->tracerProvider = $this->createMock(TracerProvider::class);
        $this->provider = $this->createMock(TracerProviderInterface::class);
        $this->tracer = $this->createMock(TracerInterface::class);

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

    public function testGetXrayPropagator()
    {
        $propagator = new Propagator();
        $this->awsSdkInstrumentation->setPropagator($propagator);

        $this->assertSame(
            $this->awsSdkInstrumentation->getPropagator(),
            $propagator
        );
    }

    public function testGetTracerProvider()
    {
        $this->assertSame(
            $this->awsSdkInstrumentation->getTracerProvider(),
            $this->tracerProvider
        );
    }

    public function testGetTracer()
    {
        $this->provider->method('getTracer')->willReturn($this->tracer);
        $this->awsSdkInstrumentation->setTracerProvider($this->provider);
        $this->assertSame($this->tracer, $this->awsSdkInstrumentation->getTracer());
    }

    public function testInstrumentationActivated()
    {
        $this->assertTrue(
            ($this->awsSdkInstrumentation)->activate()
        );
    }
}

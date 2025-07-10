<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Aws\Unit;

use DG\BypassFinals;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Aws\AwsSdkInstrumentation;
use OpenTelemetry\Contrib\Aws\Xray\Propagator;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\TestCase;

class AwsSdkInstrumentationTest extends TestCase
{
    private AwsSdkInstrumentation $awsSdkInstrumentation;

    protected function setUp(): void
    {
        BypassFinals::enable();
        $this->awsSdkInstrumentation = new AwsSdkInstrumentation();
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
        $tracerProvider = $this->createMock(TracerProviderInterface::class);
        $this->awsSdkInstrumentation->setTracerProvider($tracerProvider);

        $this->assertSame(
            $this->awsSdkInstrumentation->getTracerProvider(),
            $tracerProvider
        );
    }

    public function testGetTracer()
    {
        $tracer = $this->createMock(TracerInterface::class);
        $tracerProvider = $this->createMock(TracerProviderInterface::class);
        $tracerProvider->expects($this->once())
            ->method('getTracer')
            ->willReturn($tracer);

        $this->awsSdkInstrumentation->setTracerProvider($tracerProvider);
        $this->assertSame($tracer, $this->awsSdkInstrumentation->getTracer());
    }

    public function testInstrumentationActivated()
    {
        $this->assertTrue(
            ($this->awsSdkInstrumentation)->activate()
        );
    }
}

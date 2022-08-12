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
        $awsSdkInstrumentation = new AwsSdkInstrumentation();
        $propagator = new Propagator();
        $awsSdkInstrumentation->setPropagator($propagator);

        $this->assertSame(
            $awsSdkInstrumentation->getPropagator(),
            $propagator
        );
    }

    public function testSetTracerProvider()
    {
        $spanProcessor = new SimpleSpanProcessor(new ConsoleSpanExporter());
        $tracerProvider = new TracerProvider([$spanProcessor], null, null, null, new IdGenerator());

        $awsSdkInstrumentation = new AwsSdkInstrumentation();
        $awsSdkInstrumentation->setTracerProvider($tracerProvider);

        $this->assertInstanceOf(
            TracerProvider::class,
            $tracerProvider
        );
    }

    public function testGetTracerProvider()
    {
        $spanProcessor = new SimpleSpanProcessor(new ConsoleSpanExporter());
        $tracerProvider = new TracerProvider([$spanProcessor], null, null, null, new IdGenerator());

        $awsSdkInstrumentation = new AwsSdkInstrumentation();
        $awsSdkInstrumentation->setTracerProvider($tracerProvider);

        $this->assertSame(
            $awsSdkInstrumentation->getTracerProvider(),
            $tracerProvider
        );
    }

    public function testGetTracer()
    {
        $spanProcessor = new SimpleSpanProcessor(new ConsoleSpanExporter());
        $tracerProvider = new TracerProvider([$spanProcessor], null, null, null, new IdGenerator());

        $awsSdkInstrumentation = new AwsSdkInstrumentation();
        $awsSdkInstrumentation->setTracerProvider($tracerProvider);

        $this->assertInstanceOf(
            TracerInterface::class,
            $awsSdkInstrumentation->getTracer()
        );
    }

    public function testInstrumentationActivated()
    {
        $this->assertTrue(
            (new AwsSdkInstrumentation())->activate()
        );
    }
}

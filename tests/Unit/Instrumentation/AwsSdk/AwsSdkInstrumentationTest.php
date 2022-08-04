<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Instrumentation\AwsSdk;

use OpenTelemetry\Instrumentation\AwsSdk\AwsSdkInstrumentation;
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

    public function testInstrumentationActivated()
    {
        $this->assertTrue(
            (new AwsSdkInstrumentation())->activate()
        );
    }
}

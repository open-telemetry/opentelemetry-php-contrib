<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Instrumentation\AwsSdk;

use OpenTelemetry\Instrumentation\AwsSdk\AwsSdkInstrumentation;
use PHPUnit\Framework\TestCase;

class AwsSdkInstrumentationTest extends TestCase
{
    public function testInstrumentationClassName()
    {
        $awsSdkInstrumentation = new AwsSdkInstrumentation();

        $this->assertEquals(
            'AWS SDK Instrumentation',
            $awsSdkInstrumentation->getName()
        );
    }

    public function testInstrumentationActivated()
    {
        $awsSdkInstrumentation = new AwsSdkInstrumentation();

        $this->assertTrue(
            $awsSdkInstrumentation->activate()
        );
    }
}

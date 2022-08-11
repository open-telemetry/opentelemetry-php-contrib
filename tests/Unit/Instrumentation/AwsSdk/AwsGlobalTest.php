<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Instrumentation\AwsSdk;

use OpenTelemetry\Instrumentation\AwsSdk\AwsGlobal;
use OpenTelemetry\Instrumentation\AwsSdk\AwsSdkInstrumentation;
use PHPUnit\Framework\TestCase;

class AwsGlobalTest extends TestCase
{
    public function testSetInstrumentation()
    {
        AwsGlobal::setInstrumentation(new AwsSdkInstrumentation());

        $this->assertInstanceOf(
            AwsSdkInstrumentation::class,
            AwsGlobal::getInstrumentation()
        );
    }

    public function testGetInstrumentation()
    {
        $awsSdkInstrumentation = new AwsSdkInstrumentation();
        AwsGlobal::setInstrumentation($awsSdkInstrumentation);

        $this->assertSame(
            AwsGlobal::getInstrumentation(),
            $awsSdkInstrumentation
        );
    }
}

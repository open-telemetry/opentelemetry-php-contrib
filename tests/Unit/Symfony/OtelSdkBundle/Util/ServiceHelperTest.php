<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\Util;

use OpenTelemetry\Symfony\OtelSdkBundle\Util\ServiceHelper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\Util\ServiceHelper
 */
class ServiceHelperTest extends TestCase
{
    public function testClassToId(): void
    {
        $this->assertSame(
            'open_telemetry.symfony.otel_sdk_bundle.util.service_helper',
            ServiceHelper::classToId(ServiceHelper::class)
        );
    }

    public function testFloatToString(): void
    {
        $this->assertSame(
            '1',
            ServiceHelper::floatToString(1.0)
        );
        $this->assertSame(
            '0.5',
            ServiceHelper::floatToString(0.5)
        );
        $this->assertSame(
            '2.1234567890124',
            ServiceHelper::floatToString(2.12345678901234567890)
        );
    }
}

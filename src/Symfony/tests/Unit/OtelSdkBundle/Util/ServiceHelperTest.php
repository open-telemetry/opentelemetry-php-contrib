<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Symfony\Unit\OtelSdkBundle\Util;

use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Util\ServiceHelper;
use PHPUnit\Framework\TestCase;

class ServiceHelperTest extends TestCase
{
    public function testClassToId()
    {
        $this->assertSame(
            'open_telemetry.contrib.symfony.otel_sdk_bundle.util.service_helper',
            ServiceHelper::classToId(ServiceHelper::class)
        );
    }

    public function testFloatToString()
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
            PHP_VERSION_ID < 80400 ? '2.1234567890124' : '2.1234567890123',
            ServiceHelper::floatToString(2.12345678901234567890)
        );
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection;

use OpenTelemetry\Instrumentation\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use PHPUnit\Framework\TestCase;

class ConfigurationExceptionTest extends TestCase
{
    public function testException()
    {
        $this->expectException(
            ConfigurationException::class
        );

        throw new ConfigurationException();
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException
 */
class ConfigurationExceptionTest extends TestCase
{
    public function testException(): void
    {
        $this->expectException(
            ConfigurationException::class
        );

        throw new ConfigurationException();
    }
}

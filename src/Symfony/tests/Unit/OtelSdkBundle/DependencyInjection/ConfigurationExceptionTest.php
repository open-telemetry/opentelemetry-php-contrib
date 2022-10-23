<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Symfony\Unit\OtelSdkBundle\DependencyInjection;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
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

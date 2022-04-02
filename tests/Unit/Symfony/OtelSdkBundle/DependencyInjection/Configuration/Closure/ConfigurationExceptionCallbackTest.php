<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\ConfigurationExceptionCallback;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\ConfigurationExceptionCallback
 */
class ConfigurationExceptionCallbackTest extends TestCase
{
    public function test_create(): void
    {
        $closure = ConfigurationExceptionCallback::create('foo');

        $this->expectException(ConfigurationException::class);

        $closure();
    }
}

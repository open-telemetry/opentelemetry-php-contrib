<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\CustomServiceValidatorCallback;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\CustomServiceValidatorCallback
 */
class CustomServiceValidatorCallbackTest extends TestCase
{
    public function test_create(): void
    {
        $closure = CustomServiceValidatorCallback::create();

        $config = [
            ConfigurationInterface::TYPE_NODE => ConfigurationInterface::CUSTOM_TYPE,
            ConfigurationInterface::CLASS_NODE => get_class($this->createMock(SpanExporterInterface::class)),
        ];

        $this->assertSame(
            $config,
            $closure($config)
        );
    }
}

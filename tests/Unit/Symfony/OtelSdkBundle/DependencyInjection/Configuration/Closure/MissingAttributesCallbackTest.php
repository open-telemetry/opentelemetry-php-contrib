<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use Generator;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\MissingAttributesCallback;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\MissingAttributesCallback
 */
class MissingAttributesCallbackTest extends TestCase
{
    /**
     * @dataProvider configProvider
     */
    public function test_create_check(array $attributes, ?array $config, bool $expected): void
    {
        $closure = MissingAttributesCallback::createCheck($attributes);

        $this->assertSame($expected, $closure($config));
    }

    public function configProvider(): Generator
    {
        yield [['foo', 'bar'], ['foo' => 1, 'bar' => 2], false];
        yield [['foo', 'bar'], ['foo' => 1], true];
        yield [['foo', 'bar'], null, true];
    }

    public function test_create_exception_trigger(): void
    {
        $closure = MissingAttributesCallback::createExceptionTrigger(['foo', 'bar']);

        $this->expectException(ConfigurationException::class);

        $closure();
    }
}

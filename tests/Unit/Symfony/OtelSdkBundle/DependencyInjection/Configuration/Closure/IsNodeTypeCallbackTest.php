<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use Generator;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\IsNodeTypeCallback;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\IsNodeTypeCallback
 */
class IsNodeTypeCallbackTest extends TestCase
{
    private const TYPE_NODE = ConfigurationInterface::TYPE_NODE;
    private const NON_TYPE_NODE = 'foo';

    /**
     * @dataProvider configProvider
     */
    public function test_create(string $type, bool $isType, ?array $config, bool $expected): void
    {
        $closure = IsNodeTypeCallback::create($type, $isType);

        $this->assertSame($expected, $closure($config));
    }

    public function configProvider(): Generator
    {
        // is type
        yield ['foo', true, [self::TYPE_NODE => 'foo'], true];
        yield ['foo', true, [self::TYPE_NODE => 'bar'], false];
        yield ['foo', true, [self::NON_TYPE_NODE => 'bar'], false];
        // is not type
        yield ['foo', false, [self::TYPE_NODE => 'foo'], false];
        yield ['foo', false, [self::TYPE_NODE => 'bar'], true];
        yield ['foo', false, [self::NON_TYPE_NODE => 'bar'], true];
    }
}

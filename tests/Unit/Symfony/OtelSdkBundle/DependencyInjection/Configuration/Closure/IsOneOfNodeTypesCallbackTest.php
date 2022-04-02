<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use Generator;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\IsOneOfNodeTypesCallback;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\IsOneOfNodeTypesCallback
 */
class IsOneOfNodeTypesCallbackTest extends TestCase
{
    private const TYPE_NODE = ConfigurationInterface::TYPE_NODE;
    private const NON_TYPE_NODE = 'foo';

    /**
     * @dataProvider configProvider
     */
    public function test_create(array $types, bool $isType, ?array $config, bool $expected): void
    {
        $closure = IsOneOfNodeTypesCallback::create($types, $isType);

        $this->assertSame($expected, $closure($config));
    }

    public function configProvider(): Generator
    {
        // is one of types
        yield [['foo', 'bar'], true, [self::TYPE_NODE => 'foo'], true];
        yield [['foo', 'bar'], true, [self::TYPE_NODE => 'baz'], false];
        yield [['foo', 'bar'], true, [self::NON_TYPE_NODE => 'bar'], false];
        // is not one of types
        yield [['foo', 'bar'], false, [self::TYPE_NODE => 'foo'], false];
        yield [['foo', 'bar'], false, [self::TYPE_NODE => 'baz'], true];
        yield [['foo', 'bar'], false, [self::NON_TYPE_NODE => 'bar'], true];
    }
}

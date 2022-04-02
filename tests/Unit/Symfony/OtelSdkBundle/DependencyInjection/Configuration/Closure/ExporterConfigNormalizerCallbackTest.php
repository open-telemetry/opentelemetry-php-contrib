<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use Generator;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\ExporterConfigNormalizerCallback;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\ExporterConfigNormalizerCallback
 */
class ExporterConfigNormalizerCallbackTest extends TestCase
{

    /**
     * @dataProvider configProvider
     */
    public function test_callback($config, array $expected): void
    {
        $closure = ExporterConfigNormalizerCallback::create();

        $this->assertSame(
            $expected,
            $closure($config)
        );
    }

    public function configProvider(): Generator
    {
        // env var
        $envVar = 'env_foo123';
        yield [
            $envVar, [
                ConfigurationInterface::TYPE_NODE => ConfigurationInterface::ENV_TYPE,
                ConfigurationInterface::URL_NODE => $envVar,
            ],
        ];
        // dsn
        yield [
            [
                ConfigurationInterface::DSN_NODE => 'foo+https://host:123/path?foo=bar',
            ], [
                ConfigurationInterface::TYPE_NODE => 'foo',
                ConfigurationInterface::URL_NODE => 'https://host:123/path',
                ConfigurationInterface::OPTIONS_NODE => ['foo' => 'bar'],
            ],
        ];
        // type
        yield [
            [
                ConfigurationInterface::TYPE_NODE => 'foo',
            ], [
                ConfigurationInterface::TYPE_NODE => 'foo',
            ],
        ];
    }

    /**
     * @dataProvider exceptionProvider
     */
    public function test_callback_throws_exception($config): void
    {
        $closure = ExporterConfigNormalizerCallback::create();

        $this->expectException(ConfigurationException::class);

        $closure($config);
    }

    public function exceptionProvider(): Generator
    {
        // dsn is not a string
        yield [[ConfigurationInterface::DSN_NODE => 321]];
        yield [[ConfigurationInterface::DSN_NODE => 3.21]];
        yield [[ConfigurationInterface::DSN_NODE => []]];
        yield [[ConfigurationInterface::DSN_NODE => new stdClass()]];
        // config array does not have required keys
        yield [[]];
        // config is neither dsn nor array
        yield [321];
        yield [3.21];
        yield [new stdClass()];
    }
}

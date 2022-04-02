<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator;

use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator\TypedExporterConfigValidator;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator\TypedExporterConfigValidator
 */
class TypedExporterConfigValidatorTest extends TestCase
{
    public function test_approve_custom_class(): void
    {
        $this->expectNotToPerformAssertions();

        TypedExporterConfigValidator::approve([
            ConfigurationInterface::TYPE_NODE => ConfigurationInterface::CUSTOM_TYPE,
            ConfigurationInterface::CLASS_NODE => get_class($this->createMock(SpanExporterInterface::class)),
        ]);
    }

    public function test_approve_id(): void
    {
        $this->expectNotToPerformAssertions();

        TypedExporterConfigValidator::approve([
            ConfigurationInterface::TYPE_NODE => ConfigurationInterface::CUSTOM_TYPE,
            ConfigurationInterface::ID_NODE => 'foo',
        ]);
    }

    /**
     * @dataProvider invalidConfigProvider
     */
    public function test_disapprove(array $config): void
    {
        $this->expectException(ConfigurationException::class);

        TypedExporterConfigValidator::approve($config);
    }

    public function invalidConfigProvider(): \Generator
    {
        // no type set
        yield [[]];
        // custom type without class or id set
        yield [[ConfigurationInterface::TYPE_NODE => ConfigurationInterface::CUSTOM_TYPE]];
        // custom type with both class and id set
        yield [[
            ConfigurationInterface::TYPE_NODE => ConfigurationInterface::CUSTOM_TYPE,
            ConfigurationInterface::CLASS_NODE => __CLASS__,
            ConfigurationInterface::ID_NODE => 'foo',
        ]];
        // custom type with on-existing class
        yield [[
            ConfigurationInterface::TYPE_NODE => ConfigurationInterface::CUSTOM_TYPE,
            ConfigurationInterface::CLASS_NODE => '321',
        ]];
    }
}

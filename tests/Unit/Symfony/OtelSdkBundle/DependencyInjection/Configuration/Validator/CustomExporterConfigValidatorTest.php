<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator;

use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator\CustomExporterConfigValidator;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator\CustomExporterConfigValidator
 */
class CustomExporterConfigValidatorTest extends TestCase
{
    public function test_approve_custom_class(): void
    {
        $this->expectNotToPerformAssertions();

        CustomExporterConfigValidator::approve([
            ConfigurationInterface::CLASS_NODE => get_class($this->createMock(SpanExporterInterface::class)),
        ]);
    }

    public function test_approve_id(): void
    {
        $this->expectNotToPerformAssertions();

        CustomExporterConfigValidator::approve([ConfigurationInterface::ID_NODE => 'foo']);
    }

    /**
     * @dataProvider invalidConfigProvider
     */
    public function test_disapprove(array $config): void
    {
        $this->expectException(ConfigurationException::class);

        CustomExporterConfigValidator::approve($config);
    }

    public function invalidConfigProvider(): \Generator
    {
        // neither class nor id set
        yield [[]];
        // both class and id set
        yield [[ConfigurationInterface::CLASS_NODE => __CLASS__, ConfigurationInterface::ID_NODE => 'foo']];
        // non-existing class
        yield [[ConfigurationInterface::CLASS_NODE => '321']];
        // class not implementing SpanExporterInterface
        yield [[ConfigurationInterface::CLASS_NODE => __CLASS__]];
    }
}

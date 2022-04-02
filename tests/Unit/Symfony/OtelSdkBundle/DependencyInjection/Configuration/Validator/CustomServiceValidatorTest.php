<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator\CustomServiceValidator;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator\CustomServiceValidator
 */
class CustomServiceValidatorTest extends TestCase
{
    public function test_approve_custom_class(): void
    {
        $this->expectNotToPerformAssertions();

        CustomServiceValidator::approve([ConfigurationInterface::CLASS_NODE => __CLASS__]);
    }

    public function test_approve_id(): void
    {
        $this->expectNotToPerformAssertions();

        CustomServiceValidator::approve([ConfigurationInterface::ID_NODE => 'foo']);
    }

    /**
     * @dataProvider invalidConfigProvider
     */
    public function test_disapprove(array $config): void
    {
        $this->expectException(ConfigurationException::class);

        CustomServiceValidator::approve($config);
    }

    public function invalidConfigProvider(): \Generator
    {
        // neither class nor id set
        yield [[]];
        // both class and id set
        yield [[ConfigurationInterface::CLASS_NODE => __CLASS__, ConfigurationInterface::ID_NODE => 'foo']];
        // non-existing class
        yield [[ConfigurationInterface::CLASS_NODE => '321']];
    }
}

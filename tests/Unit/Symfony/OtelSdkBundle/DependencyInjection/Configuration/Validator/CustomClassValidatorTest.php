<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator;

use Exception;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator\CustomClassValidator;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator\CustomClassValidator
 */
class CustomClassValidatorTest extends TestCase
{
    public function test_approve(): void
    {
        CustomClassValidator::approve(Exception::class);

        $this->assertTrue(class_exists(Exception::class));
    }

    public function test_disapprove_class_not_implementing_interface(): void
    {
        $class = 'NON_EXISTING_CLASS';

        $this->assertFalse(class_exists($class));

        $this->expectException(ConfigurationException::class);

        CustomClassValidator::approve($class);
    }
}

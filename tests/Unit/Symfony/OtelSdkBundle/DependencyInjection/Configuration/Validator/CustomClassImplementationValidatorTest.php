<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator;

use Exception;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator\CustomClassImplementationValidator;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator\CustomClassImplementationValidator
 */
class CustomClassImplementationValidatorTest extends TestCase
{
    public function test_approve(): void
    {
        CustomClassImplementationValidator::approve(
            Exception::class,
            Throwable::class
        );

        $this->assertInstanceOf(Throwable::class, new Exception());
    }

    public function test_disapprove_class_not_implementing_interface(): void
    {
        $this->expectException(ConfigurationException::class);

        CustomClassImplementationValidator::approve(
            stdClass::class,
            Throwable::class
        );
    }

    public function test_disapprove_non_existing_class(): void
    {
        $class = 'NON_EXISTING_CLASS';

        $this->assertFalse(class_exists($class));

        $this->expectException(ConfigurationException::class);

        CustomClassImplementationValidator::approve(
            $class,
            Throwable::class
        );
    }

    public function test_disapprove_non_existing_interface(): void
    {
        $interface = 'NON_EXISTING_INTERFACE';

        $this->assertFalse(interface_exists($interface));

        $this->expectException(ConfigurationException::class);

        CustomClassImplementationValidator::approve(
            Exception::class,
            $interface
        );
    }
}

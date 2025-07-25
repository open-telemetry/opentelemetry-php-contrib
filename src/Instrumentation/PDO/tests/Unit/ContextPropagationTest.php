<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PDO\tests\Unit;

use OpenTelemetry\Contrib\Instrumentation\PDO\ContextPropagation;
use PHPUnit\Framework\TestCase;

class ContextPropagationTest extends TestCase
{
    public function testIsEnabledReturnsTrue()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATION'] = true;
        $result = ContextPropagation::isEnabled();
        $this->assertTrue($result);
    }

    public function testIsEnabledReturnsFalse()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATION'] = false;
        $result = ContextPropagation::isEnabled();
        $this->assertFalse($result);
    }

    public function testIsAttributeEnabledReturnsTrue()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATION_ATTRIBUTE'] = true;
        $result = ContextPropagation::isAttributeEnabled();
        $this->assertTrue($result);
    }

    public function testIsAttributeEnabledReturnsFalse()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATION_ATTRIBUTE'] = false;
        $result = ContextPropagation::isAttributeEnabled();
        $this->assertFalse($result);
    }
}

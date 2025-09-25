<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\SqlCommenter\tests\Unit;

use OpenTelemetry\Contrib\SqlCommenter\ContextPropagation;
use PHPUnit\Framework\TestCase;

class ContextPropagationTest extends TestCase
{
    public function testIsAttributeEnabledReturnsTrue()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_CONTEXT_PROPAGATION_ATTRIBUTE'] = true;
        $result = ContextPropagation::isAttributeEnabled();
        $this->assertTrue($result);
    }

    public function testIsAttributeEnabledReturnsFalse()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_CONTEXT_PROPAGATION_ATTRIBUTE'] = false;
        $result = ContextPropagation::isAttributeEnabled();
        $this->assertFalse($result);
    }
}

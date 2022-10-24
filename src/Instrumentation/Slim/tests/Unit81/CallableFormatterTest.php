<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Slim\Unit81;

use OpenTelemetry\Contrib\Instrumentation\Slim\CallableFormatter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Contrib\Instrumentation\Slim\CallableFormatter
 * PHP 8.1+ tests
 */
class CallableFormatterTest extends TestCase
{
    public function test_format_first_class_callable(): void
    {
        if (PHP_VERSION < 8.1) {
            $this->markTestSkipped();
        }
        $function = 'var_dump';
        $this->assertSame(CallableFormatter::format($function), CallableFormatter::format($function(...)));
    }

    public function test_format_first_class_callable_method(): void
    {
        $class = new TestClass();
        $this->assertStringContainsString('TestClass::method', CallableFormatter::format($class->method(...)));
    }

    public function test_format_first_class_callable_static_method(): void
    {
        $this->assertStringContainsString('TestClass::staticMethod', CallableFormatter::format(TestClass::staticMethod(...)));
    }
}

class TestClass
{
    public function method(){}
    public static function staticMethod(){}
}

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
    /**
     * @dataProvider callableProvider
     */
    public function test_format_first_class_callables(Callable $callable, string $expected): void
    {
        $this->assertSame($expected, CallableFormatter::format($callable));
    }

    public function callableProvider(): array
    {
        return [
            'first-class from built-in' => [
                var_dump(...),
                'var_dump',
            ],
            'first-class static method' => [
                TestClass::staticMethod(...),
                'TestClass::staticMethod',
            ],
            'first-class method' => [
                (new TestClass())->method(...),
                'TestClass::method',
            ],
            'first-class __invoke' => [
                (new TestClass())(...),
                'TestClass::__invoke',
            ],
            'bound first-class callable' => [
                (static fn() => null)->bindTo(null, TestClass::class),
                '{closure}',
            ],
        ];
    }
}

class TestClass
{
    public function __invoke() {}
    public function method() {}
    public static function staticMethod() {}
}

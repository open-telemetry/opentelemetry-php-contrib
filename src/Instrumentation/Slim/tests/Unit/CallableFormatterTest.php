<?php

namespace OpenTelemetry\Instrumentation\Slim\tests\Unit;

use OpenTelemetry\Contrib\Instrumentation\Slim\CallableFormatter;
use PHPUnit\Framework\TestCase;

class CallableFormatterTest extends TestCase
{
    /**
     * @dataProvider callableProvider
     */
    public function test_format($callable, string $expected): void
    {
        $this->assertSame($expected, CallableFormatter::format($callable));
    }

    public function callableProvider(): array
    {
        return [
            'string' => [
                'MyCallable',
                'MyCallable',
            ],
            'array with object' => [
                [new \stdClass(), 'foo'],
                'stdClass->foo',
            ],
            'array with strings' => [
                ['MyClass', 'foo'],
                'MyClass::foo',
            ],
            'closure' => [
                function() {},
                'Closure',
            ],
            'object' => [
                new \stdClass(),
                'stdClass',
            ],
        ];
    }

    public function test_format_with_executed_closure(): void
    {
        $closure = function(string $foo): string { return $foo; };
        $this->assertInstanceOf(\Closure::class, $closure);
        $this->assertSame('closure', $closure('bar'));
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Slim\Unit;

use Closure;
use OpenTelemetry\Contrib\Instrumentation\Slim\CallableFormatter;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OpenTelemetry\Contrib\Instrumentation\Slim\CallableFormatter
 */
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
                [new stdClass(), 'foo'],
                'stdClass->foo',
            ],
            'array with strings' => [
                ['MyClass', 'foo'],
                'MyClass::foo',
            ],
            'closure returning type' => [
                function (): stdClass {
                    return new stdClass();
                },
                'stdClass',
            ],
            'object' => [
                new stdClass(),
                'stdClass',
            ],
            'closure without return type' => [
                function () {
                },
                'callable',
            ],
            'closure from callable' => [
                Closure::fromCallable(function (): stdClass {
                    return new stdClass();
                }),
                'stdClass',
            ],
        ];
    }

    public function test_format_with_executed_closure(): void
    {
        $closure = function (): stdClass {
            return new stdClass();
        };
        $this->assertInstanceOf(Closure::class, $closure);
        $this->assertSame('stdClass', CallableFormatter::format($closure()));
    }
}

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
    public function test_format($callable, string $contains): void
    {
        $this->assertStringContainsString($contains, CallableFormatter::format($callable));
    }

    public function callableProvider(): array
    {
        return [
            'string' => [
                'MyCallable',
                'MyCallable',
            ],
            'function' => [
                'var_dump',
                'var_dump',
            ],
            'array with object' => [
                [new stdClass(), 'foo'],
                'stdClass->foo',
            ],
            'array with strings' => [
                ['MyClass', 'foo'],
                'MyClass::foo',
            ],
            'closure' => [
                function () {
                },
                '{closure}',
            ],
            'object' => [
                new stdClass(),
                'stdClass',
            ],
            'closure from callable' => [
                Closure::fromCallable(function (): stdClass {
                    return new stdClass();
                }),
                '{closure}',
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

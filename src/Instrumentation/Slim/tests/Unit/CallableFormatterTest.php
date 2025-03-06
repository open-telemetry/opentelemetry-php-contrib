<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Slim\Unit;

use OpenTelemetry\Contrib\Instrumentation\Slim\CallableFormatter;
use PHPUnit\Framework\TestCase;

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
            'builtin' => [
                'var_dump',
                'var_dump',
            ],
            [
                [TestClass::class, 'staticMethod'],
                'TestClass::staticMethod',
            ],
            [
                [new TestClass(), 'method'],
                'TestClass::method',
            ],
            [
                new TestClass(),
                'TestClass::__invoke',
            ],
            [
                static fn () => null,
                '{closure}',
            ],
            [
                (static fn () => null)->bindTo(null, TestClass::class),
                '{closure}',
            ],
            [
                (fn () => null)->bindTo(new TestClass()),
                '{closure}',
            ],
            [
                [(new class() extends TestClass {
                })::class, 'staticMethod'],
                'TestClass@anonymous::staticMethod',
            ],
            [
                [new class() extends TestClass {
                }, 'method'],
                'TestClass@anonymous::method',
            ],
            [
                new class() extends TestClass {
                },
                'TestClass@anonymous::__invoke',
            ],
            [
                new class() {
                    public function __invoke()
                    {
                    }
                },
                'class@anonymous::__invoke',
            ],
        ];
    }
}

class TestClass
{
    public function __invoke()
    {
    }
    /** @psalm-suppress PossiblyUnusedMethod */
    public function method()
    {
    }
    /** @psalm-suppress PossiblyUnusedMethod */
    public static function staticMethod()
    {
    }
}

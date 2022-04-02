<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use Closure;
use Generator;
use InvalidArgumentException;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\LogicalEndCallback;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\LogicalEndCallback
 */
class LogicalEndCallbackTest extends TestCase
{
    /**
     * @dataProvider configProvider
     */
    public function test_create(array $closures, bool $expected): void
    {
        $closure = LogicalEndCallback::create(...$closures);

        $this->assertSame($expected, $closure([]));
    }

    public function configProvider(): Generator
    {
        $true = Closure::fromCallable(static fn (): bool => true);
        $false = Closure::fromCallable(static fn (): bool => false);

        // true
        yield [[$true], true];
        yield [[$true, $true], true];
        yield [[$true, $true, $true], true];
        // false
        yield [[$false], false];
        yield [[$false, $true], false];
        yield [[$false, $false], false];
        yield [[$false, $true, $false], false];
    }

    /**
     * @dataProvider exceptionProvider
     */
    public function test_create_throws_exception_on_non_boolean_return_type(Closure $closure): void
    {
        $this->expectException(InvalidArgumentException::class);

        LogicalEndCallback::create($closure);
    }

    public function exceptionProvider(): Generator
    {
        yield [Closure::fromCallable(static fn () => true)];
        yield [Closure::fromCallable(static fn (): string => 'foo')];
        yield [Closure::fromCallable(static fn (): int => 1)];
        yield [Closure::fromCallable(static fn (): float => 1)];
        yield [Closure::fromCallable(static fn (): array => [])];
        yield [Closure::fromCallable(static fn (): object => new stdClass())];
        yield [Closure::fromCallable(static fn (): stdClass => new stdClass())];
    }
}

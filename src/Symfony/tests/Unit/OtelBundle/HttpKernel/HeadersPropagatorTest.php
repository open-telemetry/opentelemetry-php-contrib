<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\Test\Unit\OtelBundle\HttpKernel;

use OpenTelemetry\Symfony\OtelBundle\HttpKernel\HeadersPropagator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \OpenTelemetry\Symfony\OtelBundle\HttpKernel\HeadersPropagator
 */
final class HeadersPropagatorTest extends TestCase
{
    public function testHeadersPropagatorReturnsKeys(): void
    {
        $request = new Request();
        $request->headers->set('a', 'value-a');
        $request->headers->set('b', 'value-b');

        $this->assertSame(
            ['a', 'b'],
            (new HeadersPropagator())->keys($request),
        );
    }

    public function testHeadersPropagatorReturnsValueForKey(): void
    {
        $request = new Request();
        $request->headers->set('a', 'value-a');

        $this->assertSame(
            'value-a',
            (new HeadersPropagator())->get($request, 'a'),
        );
    }

    public function testHeadersPropagatorReturnsValueForKeyCaseInsensitive(): void
    {
        $request = new Request();
        $request->headers->set('a', 'value-a');

        $this->assertSame(
            'value-a',
            (new HeadersPropagator())->get($request, 'A'),
        );
    }

    public function testHeadersPropagatorReturnsConcatenatedValueForKey(): void
    {
        $request = new Request();
        $request->headers->set('a', ['value-a-1', 'value-a-2']);

        $this->assertSame(
            'value-a-1,value-a-2',
            (new HeadersPropagator())->get($request, 'a'),
        );
    }

    public function testHeadersPropagatorReturnsNullForNotExistingKey(): void
    {
        $request = new Request();
        $request->headers->set('a', 'value-a');

        $this->assertNull(
            (new HeadersPropagator())->get($request, 'b'),
        );
    }
}

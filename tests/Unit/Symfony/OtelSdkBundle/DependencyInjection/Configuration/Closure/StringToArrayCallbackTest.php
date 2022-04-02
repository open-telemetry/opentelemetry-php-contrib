<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\StringToArrayCallback;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\StringToArrayCallback
 */
class StringToArrayCallbackTest extends TestCase
{
    public function test_create(): void
    {
        $key = 'foo';
        $value = 'bar';

        $closure = StringToArrayCallback::create($key);

        $this->assertSame([$key => $value], $closure($value));
    }
}

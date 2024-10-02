<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Psr3\tests\Unit;

use OpenTelemetry\Contrib\Instrumentation\Psr3\Formatter;
use PHPUnit\Framework\TestCase;

class FormatterTest extends TestCase
{
    public function test_format(): void
    {
        $context = [
            0 => 'zero',
            'foo' => 'bar',
            'exception' => new \Exception('foo', 500, new \RuntimeException('bar')),
        ];
        $formatted = Formatter::format($context);
        $this->assertSame('bar', $formatted['foo']);
        $this->assertArrayHasKey('exception', $formatted);
        $this->assertSame('foo', $formatted['exception']['message']);
        $this->assertSame(500, $formatted['exception']['code']);
        $this->assertSame('bar', $formatted['exception']['previous']['message']);
    }
}

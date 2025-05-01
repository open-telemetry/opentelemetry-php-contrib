<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Psr3\tests\Unit;

use Exception;
use JsonSerializable;
use OpenTelemetry\Contrib\Instrumentation\Psr3\Formatter;
use PHPUnit\Framework\TestCase;
use Stringable;

class FormatterTest extends TestCase
{
    public function test_format(): void
    {
        $context = [
            0 => 'zero',
            'foo' => 'bar',
            'exception' => new Exception('foo', 500, new \RuntimeException('bar')),
            'j' => new class() implements JsonSerializable {
                public function jsonSerialize(): array
                {
                    return ['foo' => 'bar'];
                }
            },
            's' => new class() implements Stringable {
                public function __toString(): string
                {
                    return 'string_value';
                }
            },
        ];
        $formatted = Formatter::format($context);
        $this->assertSame('bar', $formatted['foo']);
        $this->assertArrayHasKey('exception', $formatted);
        $this->assertSame('foo', $formatted['exception']['message']);
        $this->assertSame(500, $formatted['exception']['code']);
        $this->assertSame('bar', $formatted['exception']['previous']['message']);
        $this->assertSame('string_value', $formatted['s']);
        $this->assertSame(['foo' => 'bar'], $formatted['j']);
    }
}

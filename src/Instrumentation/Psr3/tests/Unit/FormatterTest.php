<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Psr3\tests\Unit;

use Exception;
use JsonSerializable;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use OpenTelemetry\Contrib\Instrumentation\Psr3\Formatter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stringable;

class FormatterTest extends TestCase
{
    /** @var LogWriterInterface&MockObject */
    private LogWriterInterface $logWriter;

    public function setUp(): void
    {
        $this->logWriter = $this->createMock(LogWriterInterface::class);
        Logging::setLogWriter($this->logWriter);
    }

    public function tearDown(): void
    {
        Logging::reset();
    }

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
            'b' => true,
        ];
        $formatted = Formatter::format($context);
        $this->assertSame('bar', $formatted['foo']);
        $this->assertArrayHasKey('exception', $formatted);
        $this->assertSame('foo', $formatted['exception']['message']);
        $this->assertSame(500, $formatted['exception']['code']);
        $this->assertSame('bar', $formatted['exception']['previous']['message']);
        $this->assertSame('string_value', $formatted['s']);
        $this->assertSame(['foo' => 'bar'], $formatted['j']);
        $this->assertTrue($formatted['b']);
    }

    public function test_invalid_input_logs_warning(): void
    {
        $this->logWriter->expects($this->once())->method('write')->with(
            $this->equalTo('warning'),
            $this->stringContains('Failed to encode value'),
        );
        $context = [
            'good' => 'foo',
            'bad' => [fopen('php://memory', 'r+')], //resource cannot be encoded
        ];
        $formatted = Formatter::format($context);
        $this->assertSame('foo', $formatted['good']);
        $this->assertArrayNotHasKey('bad', $formatted);
    }
}

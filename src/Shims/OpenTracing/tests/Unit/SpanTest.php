<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Shim\OpenTracing\Unit;

use InvalidArgumentException;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Shim\OpenTracing\Span;
use PHPUnit\Framework\TestCase;

class SpanTest extends TestCase
{
    private Span $span;
    private SpanInterface $otelSpan;

    #[\Override]
    protected function setUp(): void
    {
        $this->otelSpan = $this->createMock(SpanInterface::class);
        $context = $this->createMock(ContextInterface::class);
        $this->span = new Span($this->otelSpan, $context, 'test-operation');
    }

    public function test_log_with_invalid_timestamp_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timestamp');
        /** @psalm-suppress InvalidArgument */
        $this->span->log([], 'not-a-valid-timestamp');
    }

    public function test_log_with_invalid_exception_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exception tag must be Throwable or string');
        $this->span->log(['exception' => 12345]);
    }
}

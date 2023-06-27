<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Psr3\tests\Integration;

use ArrayObject;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class Psr3InstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private SpanInterface $span;
    private TracerProvider $tracerProvider;
    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private LoggerInterface $logger;

    public function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter(new ArrayObject())
            )
        );

        $this->span = $this->tracerProvider
            ->getTracer('phpunit')
            ->spanBuilder('root')
            ->startSpan();

        $this->scope = $this->span->activate();
    }

    public function tearDown(): void
    {
        $this->span->end();
        $this->scope->detach();
    }

    public function test_log(): void
    {
        $level = LogLevel::EMERGENCY;
        $msg = 'log test';
        $context = ['user' => 'php', 'pid' => 1];

        $this->logger
            ->expects($this->once())
            ->method('log')
            ->with(
                $level,
                $msg,
                array_replace_recursive(
                    $context,
                    [
                        'traceId' => $this->span->getContext()->getTraceId(),
                        'spanId' => $this->span->getContext()->getSpanId(),
                    ],
                ),
            );

        $this->logger->log($level, $msg, $context);
    }

    /**
     * @dataProvider levelProvider
     */
    public function test_level(string $msg, string $method, array $context = []): void
    {
        $this->logger
            ->expects($this->once())
            ->method($method)
            ->with(
                $msg,
                array_replace_recursive(
                    $context,
                    [
                        'traceId' => $this->span->getContext()->getTraceId(),
                        'spanId' => $this->span->getContext()->getSpanId(),
                    ],
                ),
            );

        $this->logger->{$method}($msg, $context);
    }

    public function levelProvider(): array
    {
        return [
            ['emergency test', 'emergency', ['user' => 'php-emergency', 'pid' => 1]],
            ['alert test', 'alert', ['user' => 'php-alert', 'pid' => 1]],
            ['critical test', 'critical', ['user' => 'php-critical', 'pid' => 1]],
            ['error test', 'error', ['user' => 'php-error', 'pid' => 1]],
            ['warning test', 'warning', ['user' => 'php-warning', 'pid' => 1]],
            ['notice test', 'notice', ['user' => 'php-notice', 'pid' => 1]],
            ['info test', 'info', ['user' => 'php-info', 'pid' => 1]],
            ['debug test', 'debug', ['user' => 'php-debug', 'pid' => 1]],
            ['debug test', 'debug', ['traceId' => 'ggg', 'pid' => 1]],
            ['debug test', 'debug'],
        ];
    }
}

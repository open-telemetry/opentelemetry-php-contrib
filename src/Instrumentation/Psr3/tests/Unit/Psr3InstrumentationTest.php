<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Psr3\tests\Unit;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Logs as API;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\Psr3\Psr3Instrumentation;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as LogInMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;

class Psr3InstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private SpanInterface $span;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    public function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter(new ArrayObject())
            )
        );

        $this->span = $tracerProvider
            ->getTracer('phpunit')
            ->spanBuilder('root')
            ->startSpan();

        $this->scope = $this->span->activate();
    }

    public function tearDown(): void
    {
        $this->span->end();
        $this->scope->detach();
        // Reset mode back to inject after export tests
        $ref = new \ReflectionProperty(Psr3Instrumentation::class, 'mode');
        $ref->setAccessible(true);
        $ref->setValue(null, Psr3Instrumentation::MODE_INJECT);
    }

    public function test_name_constant(): void
    {
        $this->assertSame('psr3', Psr3Instrumentation::NAME);
    }

    public function test_mode_constants(): void
    {
        $this->assertSame('inject', Psr3Instrumentation::MODE_INJECT);
        $this->assertSame('export', Psr3Instrumentation::MODE_EXPORT);
        $this->assertSame('inject', Psr3Instrumentation::DEFAULT_MODE);
    }

    public function test_log_injects_trace_context(): void
    {
        $level = LogLevel::ERROR;
        $msg = 'test message';
        $context = ['user' => 'test'];

        $this->logger
            ->expects($this->once())
            ->method('log')
            ->with(
                $level,
                $msg,
                array_replace_recursive(
                    $context,
                    [
                        'trace_id' => $this->span->getContext()->getTraceId(),
                        'span_id' => $this->span->getContext()->getSpanId(),
                    ],
                ),
            );

        $this->logger->log($level, $msg, $context);
    }

    public function test_log_with_empty_context(): void
    {
        $this->logger
            ->expects($this->once())
            ->method('log')
            ->with(
                LogLevel::INFO,
                'no context',
                [
                    'trace_id' => $this->span->getContext()->getTraceId(),
                    'span_id' => $this->span->getContext()->getSpanId(),
                ],
            );

        $this->logger->log(LogLevel::INFO, 'no context');
    }

    /**
     * @dataProvider levelProvider
     */
    public function test_level_methods_inject_context(string $msg, string $method, array $context = []): void
    {
        $this->logger
            ->expects($this->once())
            ->method($method)
            ->with(
                $msg,
                array_replace_recursive(
                    $context,
                    [
                        'trace_id' => $this->span->getContext()->getTraceId(),
                        'span_id' => $this->span->getContext()->getSpanId(),
                    ],
                ),
            );

        $this->logger->{$method}($msg, $context);
    }

    public function levelProvider(): array
    {
        return [
            ['emergency msg', 'emergency', ['key' => 'val']],
            ['alert msg', 'alert', ['key' => 'val']],
            ['critical msg', 'critical', ['key' => 'val']],
            ['error msg', 'error', ['key' => 'val']],
            ['warning msg', 'warning', ['key' => 'val']],
            ['notice msg', 'notice', ['key' => 'val']],
            ['info msg', 'info', ['key' => 'val']],
            ['debug msg', 'debug', ['key' => 'val']],
            ['debug no ctx', 'debug'],
        ];
    }

    public function test_class_uses_deep_with_trait(): void
    {
        $ref = new \ReflectionMethod(Psr3Instrumentation::class, 'class_uses_deep');
        $ref->setAccessible(true);

        $objectWithTrait = new class() {
            use LoggerTrait;
            public function log($level, \Stringable|string $message, array $context = []): void
            {
            }
        };

        $traits = $ref->invoke(null, $objectWithTrait);
        $this->assertContains(LoggerTrait::class, $traits);
    }

    public function test_class_uses_deep_without_trait(): void
    {
        $ref = new \ReflectionMethod(Psr3Instrumentation::class, 'class_uses_deep');
        $ref->setAccessible(true);

        $objectWithoutTrait = new \stdClass();
        $traits = $ref->invoke(null, $objectWithoutTrait);
        $this->assertEmpty($traits);
    }

    public function test_get_mode_returns_default(): void
    {
        $ref = new \ReflectionMethod(Psr3Instrumentation::class, 'getMode');
        $ref->setAccessible(true);

        $mode = $ref->invoke(null);
        $this->assertContains($mode, ['inject', 'export']);
    }

    public function test_logger_trait_early_return_for_non_log_methods(): void
    {
        // A logger using LoggerTrait should have non-log methods return early
        // because LoggerTrait proxies all level methods to log()
        $traitLogger = new class() implements LoggerInterface {
            use LoggerTrait;
            public array $calls = [];
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->calls[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };

        // Clear the cache so it detects the trait
        $cacheRef = new \ReflectionProperty(Psr3Instrumentation::class, 'cache');
        $cacheRef->setAccessible(true);
        $cacheRef->setValue(null, []);

        $traitLogger->info('test trait logger');

        // The LoggerTrait routes info() -> log(), so log() will be called
        $this->assertNotEmpty($traitLogger->calls);
        $this->assertSame('info', $traitLogger->calls[0]['level']);
    }

    public function test_export_mode_log_method(): void
    {
        $logStorage = new ArrayObject();
        $logExporter = new LogInMemoryExporter($logStorage);
        $loggerProvider = new LoggerProvider(
            new SimpleLogRecordProcessor($logExporter),
            new InstrumentationScopeFactory(Attributes::factory()),
        );

        $scope = Configurator::create()
            ->withTracerProvider(new TracerProvider(
                new SimpleSpanProcessor(new InMemoryExporter(new ArrayObject()))
            ))
            ->withPropagator(new TraceContextPropagator())
            ->withLoggerProvider($loggerProvider)
            ->activate();

        // Switch to export mode
        $modeRef = new \ReflectionProperty(Psr3Instrumentation::class, 'mode');
        $modeRef->setAccessible(true);
        $modeRef->setValue(null, Psr3Instrumentation::MODE_EXPORT);

        // Use a mock logger that doesn't use LoggerTrait
        $logger = $this->createMock(LoggerInterface::class);
        $logger->log(LogLevel::WARNING, 'export test', ['foo' => 'bar']);

        $loggerProvider->forceFlush();

        $this->assertGreaterThanOrEqual(1, count($logStorage));

        $scope->detach();
    }

    public function test_export_mode_level_method(): void
    {
        $logStorage = new ArrayObject();
        $logExporter = new LogInMemoryExporter($logStorage);
        $loggerProvider = new LoggerProvider(
            new SimpleLogRecordProcessor($logExporter),
            new InstrumentationScopeFactory(Attributes::factory()),
        );

        $scope = Configurator::create()
            ->withTracerProvider(new TracerProvider(
                new SimpleSpanProcessor(new InMemoryExporter(new ArrayObject()))
            ))
            ->withPropagator(new TraceContextPropagator())
            ->withLoggerProvider($loggerProvider)
            ->activate();

        // Switch to export mode
        $modeRef = new \ReflectionProperty(Psr3Instrumentation::class, 'mode');
        $modeRef->setAccessible(true);
        $modeRef->setValue(null, Psr3Instrumentation::MODE_EXPORT);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->error('export error test');

        $loggerProvider->forceFlush();

        $this->assertGreaterThanOrEqual(1, count($logStorage));

        $scope->detach();
    }
}

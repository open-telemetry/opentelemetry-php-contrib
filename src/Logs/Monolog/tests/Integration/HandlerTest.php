<?php

declare(strict_types=1);

namespace Integration;

use ArrayObject;
use Monolog\Level;
use Monolog\Logger;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\Contrib\Logs\Monolog\Handler;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogsProcessor;
use OpenTelemetry\SDK\Logs\ReadWriteLogRecord;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class HandlerTest extends TestCase
{
    private ArrayObject $storage;
    private Logger $logger;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $exporter = new InMemoryExporter($this->storage);
        $loggerProvider = new LoggerProvider(
            new SimpleLogsProcessor($exporter),
            new InstrumentationScopeFactory(Attributes::factory()),
        );
        $handler = new Handler(loggerProvider: $loggerProvider, level: Level::Warning);
        $this->logger = new Logger('test', [$handler]);
    }

    public function test_log_error(): void
    {
        $this->assertCount(0, $this->storage);
        $this->logger->error('foo');
        $this->assertCount(1, $this->storage);
        /** @var ReadWriteLogRecord $record */
        $record = $this->storage->offsetGet(0);
        $this->assertInstanceOf(LogRecord::class, $record);
        $this->assertSame('error', $record->getSeverityText());
        $this->assertSame(17, $record->getSeverityNumber());
        $this->assertGreaterThan(0, $record->getTimestamp());
        $this->assertSame('monolog', $record->getInstrumentationScope()->getName());
    }

    public function test_log_debug_is_not_handled(): void
    {
        //handler is configured with warning level, so debug should be ignored
        $this->assertCount(0, $this->storage);
        $this->logger->debug('debug message');
        $this->assertCount(0, $this->storage);
    }
}

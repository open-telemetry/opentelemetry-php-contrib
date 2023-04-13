<?php

declare(strict_types=1);

namespace Integration;

use ArrayObject;
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
        $handler = new Handler($loggerProvider, 200);
        $this->logger = new Logger('test');
        $this->logger->pushHandler($handler);
    }

    public function test_log_info(): void
    {
        $this->assertCount(0, $this->storage);
        /** @psalm-suppress UndefinedDocblockClass */
        $this->logger->info('foo');
        $this->assertCount(1, $this->storage);
        /** @var ReadWriteLogRecord $record */
        $record = $this->storage->offsetGet(0);
        $this->assertInstanceOf(LogRecord::class, $record);
        $this->assertSame('INFO', $record->getSeverityText());
        $this->assertSame(9, $record->getSeverityNumber());
        $this->assertGreaterThan(0, $record->getTimestamp());
        $this->assertSame('monolog', $record->getInstrumentationScope()->getName());
    }

    public function test_log_debug_is_not_handled(): void
    {
        //handler is configured with info level, so debug should be ignored
        $this->assertCount(0, $this->storage);
        /** @psalm-suppress UndefinedDocblockClass */
        $this->logger->debug('debug message');
        $this->assertCount(0, $this->storage);
    }
}

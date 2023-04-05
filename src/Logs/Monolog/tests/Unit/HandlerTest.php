<?php

declare(strict_types=1);

use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\Contrib\Logs\Monolog\Handler;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Logs\LoggerSharedState;
use OpenTelemetry\SDK\Logs\LogRecordLimits;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Contrib\Logs\Monolog\Handler
 */
class HandlerTest extends TestCase
{
    /**
     * @var LoggerInterface&PHPUnit\Framework\MockObject\MockObject $logger
     */
    private LoggerInterface $logger;
    private LoggerProviderInterface $provider;

    public function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->provider = $this->createMock(LoggerProviderInterface::class);
        $this->provider->method('getLogger')->willReturn($this->logger);
    }

    /**
     * @dataProvider levelProvider
     */
    public function test_is_handling(Level $level, bool $expected): void
    {
        $handler = new Handler($this->provider, Level::Error, true);
        $logger = new Logger('otel', [$handler]);

        $this->assertSame($expected, $logger->isHandling($level));
    }

    public static function levelProvider(): array
    {
        return [
            'debug' => [Level::Debug, false],
            'info' => [Level::Info, false],
            'warning' => [Level::Warning, false],
            'error' => [Level::Error, true],
            'alert' => [Level::Alert, true],
            'emergency' => [Level::Emergency, true],
        ];
    }

    /**
     * @dataProvider levelProvider
     */
    public function test_handle_ignores_not_handling(Level $level, bool $expected): void
    {
        $handler = new Handler($this->provider, Level::Error, true);
        $logger = new Logger('otel', [$handler]);

        if ($expected) {
            $this->logger->expects($this->once())->method('logRecord');
        } else {
            $this->logger->expects($this->never())->method('logRecord');
        }

        $logger->log($level, 'foo');
    }

    public function test_handle_record(): void
    {
        $scope = $this->createMock(InstrumentationScopeInterface::class);
        $sharedState = $this->createMock(LoggerSharedState::class);
        $resource = $this->createMock(ResourceInfo::class);
        $limits = $this->createMock(LogRecordLimits::class);
        $attributeFactory = Attributes::factory();
        $limits->method('getAttributeFactory')->willReturn($attributeFactory);
        $sharedState->method('getResource')->willReturn($resource);
        $sharedState->method('getLogRecordLimits')->willReturn($limits);
        $handler = new Handler($this->provider, Level::Error, true);

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'channel',
            Level::Error,
            'message',
            ['foo' => 'bar', 'exception' => new \Exception('kaboom', 500)],
            ['extra' => 'baz'],
        );
        $this->logger
            ->expects($this->once())
            ->method('logRecord')
            ->with($this->callback(
                function (\OpenTelemetry\API\Logs\LogRecord $logRecord) use ($scope, $sharedState) {
                    $readable = new ReadableLogRecord($scope, $sharedState, $logRecord, false);
                    $this->assertSame('error', $readable->getSeverityText());
                    $this->assertSame(17, $readable->getSeverityNumber());
                    $this->assertGreaterThan(0, $readable->getTimestamp());
                    $this->assertSame('message', $readable->getBody());
                    $attributes = $readable->getAttributes();
                    $this->assertCount(3, $attributes);
                    $this->assertSame('bar', $attributes->get('foo'));
                    $this->assertSame('baz', $attributes->get('extra'));
                    $this->assertNotNull($attributes->get('exception'));

                    return true;
                }
            ));

        $handler->handle($logRecord);
    }

    public function test_handle_batch(): void
    {
        $handler = new Handler($this->provider, Level::Error, true);
        $record = new LogRecord(new DateTimeImmutable(), 'foo', Level::Error, 'message');
        $records = [$record, $record, $record];
        $this->logger->expects($this->exactly(count($records)))->method('logRecord');

        $handler->handleBatch($records);
    }
}

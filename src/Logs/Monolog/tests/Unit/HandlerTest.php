<?php

declare(strict_types=1);

use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Logs\LogRecord;
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
    /** @var LoggerProviderInterface&\PHPUnit\Framework\MockObject\MockObject $provider */
    private LoggerProviderInterface $provider;

    public function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->provider = $this->createMock(LoggerProviderInterface::class);
        $this->provider->method('getLogger')->willReturn($this->logger);
    }

    public function test_handle_record(): void
    {
        $channelName = 'test';
        $this->provider->expects($this->once())
            ->method('getLogger')
            ->with($this->equalTo($channelName)); //logger name comes from monolog channel
        $scope = $this->createMock(InstrumentationScopeInterface::class);
        $sharedState = $this->createMock(LoggerSharedState::class);
        $resource = $this->createMock(ResourceInfo::class);
        $limits = $this->createMock(LogRecordLimits::class);
        $attributeFactory = Attributes::factory();
        $limits->method('getAttributeFactory')->willReturn($attributeFactory);
        $sharedState->method('getResource')->willReturn($resource);
        $sharedState->method('getLogRecordLimits')->willReturn($limits);
        $handler = new Handler($this->provider, 100, true);
        $processor = function ($record) {
            $baz = new stdClass();
            $baz->bat = 'ball';
            $record['extra'] = ['foo' => 'bar', 'baz' => $baz];

            return $record;
        };
        $monolog = new \Monolog\Logger($channelName);
        $monolog->pushHandler($handler);
        $monolog->pushProcessor($processor);

        $this->logger
            ->expects($this->once())
            ->method('emit')
            ->with($this->callback(
                function (LogRecord $logRecord) use ($scope, $sharedState) {
                    $readable = new ReadableLogRecord($scope, $sharedState, $logRecord);
                    $this->assertSame('INFO', $readable->getSeverityText());
                    $this->assertSame(9, $readable->getSeverityNumber());
                    $this->assertGreaterThan(0, $readable->getTimestamp());
                    $this->assertSame('message', $readable->getBody());
                    $attributes = $readable->getAttributes();
                    $this->assertCount(8, $attributes);
                    $this->assertEqualsCanonicalizing([
                        'context',
                        'context.foo',
                        'extra',
                        'extra.foo',
                        'extra.baz',
                        'exception.message',
                        'exception.type',
                        'exception.stacktrace',
                    ], array_keys($attributes->toArray()));
                    $this->assertSame('bar', $attributes->get('context')['foo']);
                    $this->assertSame('bar', $attributes->get('context.foo'));
                    $this->assertSame('bar', $attributes->get('extra')['foo']);
                    $this->assertSame('ball', $attributes->get('extra')['baz']['stdClass']['bat']);
                    $this->assertSame('bar', $attributes->get('extra.foo'));
                    $this->assertSame('ball', $attributes->get('extra.baz')->bat);
                    $this->assertSame('kaboom', $attributes->get('exception.message'));
                    $this->assertSame('Exception', $attributes->get('exception.type'));
                    $this->assertNotNull($attributes->get('exception.stacktrace'));

                    return true;
                }
            ));

        /** @psalm-suppress UndefinedDocblockClass */
        $monolog->info('message', ['foo' => 'bar', 'exception' => new \Exception('kaboom', 500)]);
    }

    public function test_handle_record_attrib_mode(): void
    {
        putenv(sprintf('%s=%s', Handler::OTEL_PHP_MONOLOG_ATTRIB_MODE, Handler::MODE_OTEL));

        $channelName = 'test';
        $scope = $this->createMock(InstrumentationScopeInterface::class);
        $sharedState = $this->createMock(LoggerSharedState::class);
        $resource = $this->createMock(ResourceInfo::class);
        $limits = $this->createMock(LogRecordLimits::class);
        $attributeFactory = Attributes::factory();
        $limits->method('getAttributeFactory')->willReturn($attributeFactory);
        $sharedState->method('getResource')->willReturn($resource);
        $sharedState->method('getLogRecordLimits')->willReturn($limits);
        $handler = new Handler($this->provider, 100, true);
        $processor = function ($record) {
            $record['extra'] = ['foo' => 'qux', 'bar' => 'quux'];

            return $record;
        };
        $monolog = new \Monolog\Logger($channelName);
        $monolog->pushHandler($handler);
        $monolog->pushProcessor($processor);

        $this->logger
            ->expects($this->once())
            ->method('emit')
            ->with($this->callback(
                function (LogRecord $logRecord) use ($scope, $sharedState) {
                    $readable = new ReadableLogRecord($scope, $sharedState, $logRecord);
                    $attributes = $readable->getAttributes();
                    $this->assertCount(6, $attributes);
                    $this->assertEqualsCanonicalizing([
                        'foo',
                        'bar',
                        'baz',
                        'exception.message',
                        'exception.type',
                        'exception.stacktrace',
                    ], array_keys($attributes->toArray()));
                    $this->assertSame('qux', $attributes->get('foo'));
                    $this->assertSame('quux', $attributes->get('bar'));
                    $this->assertSame('quuux', $attributes->get('baz'));
                    $this->assertSame('kaboom', $attributes->get('exception.message'));
                    $this->assertSame('Exception', $attributes->get('exception.type'));
                    $this->assertNotNull($attributes->get('exception.stacktrace'));

                    return true;
                }
            ));

        /** @psalm-suppress UndefinedDocblockClass */
        $monolog->info('message', ['baz' => 'quuux', 'exception' => new \Exception('kaboom', 500)]);
    }
}

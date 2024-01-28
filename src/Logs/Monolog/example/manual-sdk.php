<?php

declare(strict_types=1);

/**
 * This example manually creates an OpenTelemetry LoggerProvider, and passes it to the handler.
 * Logs are send to console.
 */

use Monolog\Logger;
use OpenTelemetry\Contrib\Logs\Monolog\Handler;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Logs\Exporter\ConsoleExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use Psr\Log\LogLevel;

require dirname(__DIR__) . '/vendor/autoload.php';

$loggerProvider = new LoggerProvider(
    new BatchLogRecordProcessor(
        new ConsoleExporter((new StreamTransportFactory())->create('php://stdout', 'text')),
        ClockFactory::getDefault()
    ),
    new InstrumentationScopeFactory(Attributes::factory()),
);

$handler = new Handler(
    $loggerProvider,
    LogLevel::ERROR,
);
$logger = new Logger('example', [$handler]);

$logger->info('hello, world');
$logger->error('oh no', [
    'foo' => 'bar',
    'exception' => new \Exception('something went wrong'),
]);

$loggerProvider->shutdown();

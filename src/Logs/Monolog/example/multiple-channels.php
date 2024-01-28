<?php

declare(strict_types=1);

use Monolog\Logger;
use OpenTelemetry\Contrib\Logs\Monolog\Handler;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use Psr\Log\LogLevel;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Example of using the handler in two channels. The logs will each be attached to a different scope, named
 * for the logger name/channel.
 */

$loggerProvider = new LoggerProvider(
    new BatchLogRecordProcessor(
        new LogsExporter((new StreamTransportFactory())->create('php://stdout', 'application/json')),
        ClockFactory::getDefault()
    ),
    new InstrumentationScopeFactory(Attributes::factory()),
);

$handler = new Handler(
    $loggerProvider,
    LogLevel::INFO,
);
$logger_one = new Logger('monolog-one', [$handler]);
$logger_two = new Logger('monolog-two', [$handler]);

$logger_one->info('hello from logger one');
$logger_two->info('hello from logger two');

$loggerProvider->shutdown();

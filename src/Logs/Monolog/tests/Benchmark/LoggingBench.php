<?php


use Monolog\Logger;
use OpenTelemetry\Contrib\Logs\Monolog\Handler;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogsProcessor;

class LoggingBench
{
    private array $loggers = [];
    private ArrayObject $storage;

    public function setUp(array $params): void
    {
        $provider = new \OpenTelemetry\SDK\Logs\NoopLoggerProvider();
        $handler = new Handler($provider, \Psr\Log\LogLevel::INFO);
        for ($i=0; $i<$params[0]; $i++) {
            $this->loggers[$i] = new Logger('channel_' . $i, [$handler]);
        }
    }

    /**
     * @BeforeMethods("setUp")
     * @ParamProviders("provideChannelCounts")
     * @Revs(10)
     * @Iterations(1)
     * @OutputTimeUnit("microseconds")
     */
    public function benchEmitLogs(array $params): void
    {
        for ($i=0; $i<$params[1]; $i++) {
            $this->loggers[array_rand($this->loggers)]->info('hello world');
        }
    }

    public function provideChannelCounts(): \Generator
    {
        yield '1 channel, 100 logs' => [1, 100];
        yield '1 channel, 10000 logs' => [1, 1000];
        yield '4 channels, 100 logs' => [4, 100];
        yield '4 channels, 10000 logs' => [4, 10000];
        yield '16 channels, 100 logs' => [16, 100];
        yield '16 channels, 10000 logs' => [16, 10000];
        yield '256 channels, 100 logs' => [256, 100];
        yield '256 channels, 10000 logs' => [256, 10000];
    }
}
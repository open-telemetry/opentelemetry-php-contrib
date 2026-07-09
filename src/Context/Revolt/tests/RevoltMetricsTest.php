<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Context\Revolt;

use OpenTelemetry\API\Configuration\Noop\NoopConfigProperties;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\NoopHookManager;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Revolt\EventLoop\DriverFactory;

/** @covers \OpenTelemetry\Contrib\Context\Revolt\RevoltMetrics */
final class RevoltMetricsTest extends TestCase
{
    private EventLoop\Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = EventLoop::getDriver();
        EventLoop::setDriver(RevoltDriver::wrap((new DriverFactory())->create()));
    }

    protected function tearDown(): void
    {
        EventLoop::run();
        EventLoop::setDriver($this->driver);

        parent::tearDown();
    }

    public function testEventLoopMetricsObserveCallbacks(): void
    {
        $meterProvider = (new MeterProviderBuilder())
            ->addReader($reader = new ExportingReader($exporter = new InMemoryExporter()))
            ->build();

        $instrumentation = new RevoltMetrics();
        $instrumentation->register(new NoopHookManager(), new NoopConfigProperties(), new Context(
            meterProvider: $meterProvider,
        ));

        $reader->collect();
        $reader->forceFlush();
        $exporter->collect(reset: true);

        $reader->collect();
        $reader->forceFlush();
        $metrics = $exporter->collect(reset: true);

        $this->assertEquals([], $this->indexCallbackMetric($metrics));

        $callbacks = [
            EventLoop::defer(static fn () => null),
            EventLoop::defer(static fn () => null),
            EventLoop::delay(0.5, static fn () => null),
            EventLoop::repeat(0.5, static fn () => null),
            EventLoop::unreference(EventLoop::defer(static fn () => null)),
            EventLoop::disable(EventLoop::repeat(0.5, static fn () => null)),
        ];

        try {
            $reader->collect();
            $reader->forceFlush();
            $metrics = $exporter->collect(reset: true);

            $this->assertEquals(
                [
                    'defer' => [
                        'referenced' => 2,
                        'unreferenced' => 1,
                    ],
                    'delay' => [
                        'referenced' => 1,
                    ],
                    'repeat' => [
                        'referenced' => 1,
                        'disabled' => 1,
                    ],
                ],
                $this->indexCallbackMetric($metrics),
            );
        } finally {
            foreach ($callbacks as $callback) {
                EventLoop::cancel($callback);
            }
        }
    }

    /**
     * @param list<Metric> $metrics
     * @return array<string, array<string, int<1, max>>
     */
    private function indexCallbackMetric(array $metrics): array
    {
        $indexed = [];
        foreach ($metrics as $metric) {
            $this->assertSame('php.revolt.eventloop.callbacks', $metric->name);
            $this->assertInstanceOf(Sum::class, $metric->data);

            $indexed = [];
            foreach ($metric->data->dataPoints as $dataPoint) {
                $this->assertTrue($dataPoint->attributes->has('php.revolt.eventloop.callback.type'));
                $this->assertTrue($dataPoint->attributes->has('php.revolt.eventloop.callback.state'));

                if ($dataPoint->value === 0) {
                    continue;
                }

                $indexed[$dataPoint->attributes->get('php.revolt.eventloop.callback.type')][$dataPoint->attributes->get('php.revolt.eventloop.callback.state')] = $dataPoint->value;
            }
        }

        return $indexed;
    }
}

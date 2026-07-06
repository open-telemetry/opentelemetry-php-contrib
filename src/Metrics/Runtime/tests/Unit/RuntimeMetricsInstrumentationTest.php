<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime\Tests\Unit;

use OpenTelemetry\API\Configuration\ConfigProperties;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\Contrib\Metrics\Runtime\RuntimeMetricsConfig;
use OpenTelemetry\Contrib\Metrics\Runtime\RuntimeMetricsInstrumentation;
use PHPUnit\Framework\TestCase;

class RuntimeMetricsInstrumentationTest extends TestCase
{
    /** @param list<string> $requestedNames */
    private function makeMeterProvider(array &$requestedNames): MeterProviderInterface
    {
        $meter = $this->createMock(MeterInterface::class);
        $meter->method('createObservableUpDownCounter')
            ->willReturn($this->createMock(ObservableUpDownCounterInterface::class));
        $meter->method('createObservableGauge')
            ->willReturn($this->createMock(ObservableGaugeInterface::class));
        $meter->method('createObservableCounter')
            ->willReturn($this->createMock(ObservableCounterInterface::class));
        $meter->method('batchObserve')
            ->willReturn($this->createMock(ObservableCallbackInterface::class));

        $meterProvider = $this->createMock(MeterProviderInterface::class);
        $meterProvider->method('getMeter')
            ->willReturnCallback(function (string $name) use ($meter, &$requestedNames): MeterInterface {
                $requestedNames[] = $name;

                return $meter;
            });

        return $meterProvider;
    }

    public function test_register_without_config_registers_all_groups(): void
    {
        $requestedNames = [];
        $meterProvider = $this->makeMeterProvider($requestedNames);

        $configuration = $this->createMock(ConfigProperties::class);
        $configuration->method('get')->willReturn(null);

        (new RuntimeMetricsInstrumentation())->register(
            $this->createMock(HookManagerInterface::class),
            $configuration,
            new Context(meterProvider: $meterProvider),
        );

        $this->assertContains('io.opentelemetry.contrib.php.runtime.memory', $requestedNames);
    }

    public function test_register_with_config_skips_disabled_groups(): void
    {
        $requestedNames = [];
        $meterProvider = $this->makeMeterProvider($requestedNames);

        $configuration = $this->createMock(ConfigProperties::class);
        $configuration->method('get')->willReturn(new RuntimeMetricsConfig(['memory', 'gc', 'opcache', 'cpu']));

        (new RuntimeMetricsInstrumentation())->register(
            $this->createMock(HookManagerInterface::class),
            $configuration,
            new Context(meterProvider: $meterProvider),
        );

        $this->assertSame([], $requestedNames);
    }
}

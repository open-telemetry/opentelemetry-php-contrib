<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime\Tests\Unit;

use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoaderRegistry;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvResolver;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\Contrib\Metrics\Runtime\InstrumentationConfigurationRuntimeMetricsConfig;
use OpenTelemetry\Contrib\Metrics\Runtime\RuntimeMetricsConfig;
use PHPUnit\Framework\TestCase;

class InstrumentationConfigurationRuntimeMetricsConfigTest extends TestCase
{
    private function load(?array $disabledInstrumentations): RuntimeMetricsConfig
    {
        $env = $this->createMock(EnvResolver::class);
        $env->method('list')
            ->with('OTEL_PHP_DISABLED_INSTRUMENTATIONS')
            ->willReturn($disabledInstrumentations);

        $config = (new InstrumentationConfigurationRuntimeMetricsConfig())->load(
            $env,
            $this->createMock(EnvComponentLoaderRegistry::class),
            new Context(),
        );

        $this->assertInstanceOf(RuntimeMetricsConfig::class, $config);

        return $config;
    }

    public function test_name_returns_config_class(): void
    {
        $this->assertSame(RuntimeMetricsConfig::class, (new InstrumentationConfigurationRuntimeMetricsConfig())->name());
    }

    public function test_nothing_disabled_by_default(): void
    {
        $this->assertSame([], $this->load(null)->disabled);
    }

    public function test_single_group_disabled(): void
    {
        $this->assertSame(['opcache'], $this->load(['metrics-runtime-opcache'])->disabled);
    }

    public function test_multiple_groups_disabled(): void
    {
        $this->assertEqualsCanonicalizing(
            ['memory', 'cpu'],
            $this->load(['metrics-runtime-memory', 'metrics-runtime-cpu'])->disabled,
        );
    }

    public function test_whole_package_name_disables_all_groups(): void
    {
        $this->assertEqualsCanonicalizing(
            ['memory', 'gc', 'opcache', 'cpu'],
            $this->load(['metrics-runtime'])->disabled,
        );
    }

    public function test_all_disables_all_groups(): void
    {
        $this->assertEqualsCanonicalizing(
            ['memory', 'gc', 'opcache', 'cpu'],
            $this->load(['all'])->disabled,
        );
    }

    public function test_unrelated_instrumentation_names_are_ignored(): void
    {
        $this->assertSame([], $this->load(['guzzle', 'pdo'])->disabled);
    }
}

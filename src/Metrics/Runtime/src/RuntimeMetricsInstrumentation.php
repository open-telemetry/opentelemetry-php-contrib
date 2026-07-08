<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime;

use OpenTelemetry\API\Configuration\ConfigProperties;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;

class RuntimeMetricsInstrumentation implements Instrumentation
{
    public function register(HookManagerInterface $hookManager, ConfigProperties $configuration, Context $context): void
    {
        $config = $configuration->get(RuntimeMetricsConfig::class);
        $config = $config instanceof RuntimeMetricsConfig ? $config : new RuntimeMetricsConfig();

        RuntimeMetrics::register($context->meterProvider, $config);
    }
}

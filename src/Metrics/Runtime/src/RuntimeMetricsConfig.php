<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime;

use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;

/**
 * @internal
 */
final class RuntimeMetricsConfig implements InstrumentationConfiguration
{
    /** @param list<string> $disabled */
    public function __construct(
        public readonly array $disabled = [],
    ) {
    }
}

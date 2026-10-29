<?php

declare (strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr18\Configuration;

use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;

final class Psr18Configuration implements InstrumentationConfiguration
{
    public function __construct(
        public readonly bool $enabled = true,
    ) {
    }
}

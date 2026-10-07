<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Logs\Monolog;

use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;

final class HandlerConfiguration implements InstrumentationConfiguration
{
    public function __construct(
        public readonly string $mode = Handler::DEFAULT_MODE,
    ) {
    }
}

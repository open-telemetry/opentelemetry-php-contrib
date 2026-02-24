<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use const PHP_SAPI;

final class LaravelConfiguration implements InstrumentationConfiguration
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly bool $traceCliEnabled = false,
    ) {
    }

    public function shouldTraceCli(): bool
    {
        return PHP_SAPI !== 'cli' || $this->traceCliEnabled;
    }
}

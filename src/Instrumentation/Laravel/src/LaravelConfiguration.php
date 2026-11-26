<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpConfig;
use const PHP_SAPI;

final class LaravelConfiguration implements InstrumentationConfiguration
{
    private ?HttpConfig $httpConfig = null;

    public function __construct(
        public readonly bool $enabled = true,
        public readonly bool $traceCliEnabled = false,
    ) {
    }

    public function shouldTraceCli(): bool
    {
        return $this->traceCliEnabled && in_array(PHP_SAPI, ['cli', 'phpdbg'], true);
    }

    public function getHttpConfig(): HttpConfig
    {
        return $this->httpConfig;
    }

    public function setHttpConfig(HttpConfig $httpConfig): void
    {
        if (! $this->httpConfig) {
            $this->httpConfig = $httpConfig;
        }
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use OpenTelemetry\SDK\Sdk;

final class LaravelConfiguration implements InstrumentationConfiguration
{
    private function __construct(
        public readonly bool $enabled,
    ) {
    }

    public static function fromArray(array $properties): self
    {
        return new self(
            enabled: $properties['enabled'] ?? (class_exists(Sdk::class) ? !Sdk::isDisabled() : true),
        );
    }

    public static function default(): self
    {
        return self::fromArray([]);
    }
}

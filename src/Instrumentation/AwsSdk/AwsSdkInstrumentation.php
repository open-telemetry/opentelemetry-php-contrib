<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\AwsSdk;

use OpenTelemetry\API\Common\Instrumentation\InstrumentationInterface;
use OpenTelemetry\API\Common\Instrumentation\InstrumentationTrait;

class AwsSdkInstrumentation implements InstrumentationInterface
{
    use InstrumentationTrait;

    public function __construct()
    {
    }

    public function getName(): string
    {
        return 'AWS SDK Instrumentation';
    }

    public function getVersion(): ?string
    {
        return '0.0.1';
    }

    public function getSchemaUrl(): ?string
    {
        return null;
    }

    public function init(): bool
    {
        return true;
    }

    public function activate(): bool
    {
        return true;
    }
}

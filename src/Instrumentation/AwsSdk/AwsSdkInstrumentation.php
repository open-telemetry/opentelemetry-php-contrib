<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\AwsSdk;

use OpenTelemetry\API\Common\Instrumentation\InstrumentationInterface;
use OpenTelemetry\API\Common\Instrumentation\InstrumentationTrait;

/**
 * @experimental
 */
class AwsSdkInstrumentation implements InstrumentationInterface
{
    use InstrumentationTrait;

    public const NAME = 'AWS SDK Instrumentation';
    public const VERSION = '0.0.1';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getVersion(): ?string
    {
        return self::VERSION;
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

<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\AwsSdk;

class AwsGlobal
{

    private static $instrumentation_instance;

    public static function setInstrumentation($configuredAwsSdkInstrumentation): void
    {
        self::$instrumentation_instance = $configuredAwsSdkInstrumentation;
    }

    public static function getInstrumentation()
    {
        return self::$instrumentation_instance;
    }
}

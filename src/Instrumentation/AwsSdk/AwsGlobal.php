<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\AwsSdk;

class AwsGlobal
{
    private static AwsSdkInstrumentation $instrumentation_instance;

    public static function setInstrumentation(AwsSdkInstrumentation $configuredAwsSdkInstrumentation): void
    {
        self::$instrumentation_instance = $configuredAwsSdkInstrumentation;
    }

    public static function getInstrumentation(): AwsSdkInstrumentation
    {
        return self::$instrumentation_instance;
    }
}

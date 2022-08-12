<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\AwsSdk;

class AwsGlobal
{
    private static AwsSdkInstrumentation $instrumentationInstance;

    public static function setInstrumentation(AwsSdkInstrumentation $configuredAwsSdkInstrumentation): void
    {
        self::$instrumentationInstance = $configuredAwsSdkInstrumentation;
    }

    public static function getInstrumentation(): AwsSdkInstrumentation
    {
        return self::$instrumentationInstance;
    }
}

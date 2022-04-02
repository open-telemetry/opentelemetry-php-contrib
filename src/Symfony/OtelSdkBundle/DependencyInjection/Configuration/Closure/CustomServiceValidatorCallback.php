<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use Closure;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator\CustomServiceValidator;

class CustomServiceValidatorCallback
{
    public static function create(): Closure
    {
        return Closure::fromCallable(static function (array $config): array {
            CustomServiceValidator::approve($config);

            return $config;
        });
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use Closure;

class StringToArrayCallback
{
    public static function create(string $key): Closure
    {
        return Closure::fromCallable(static function (string $value) use ($key): array {
            return [$key => $value];
        });
    }
}

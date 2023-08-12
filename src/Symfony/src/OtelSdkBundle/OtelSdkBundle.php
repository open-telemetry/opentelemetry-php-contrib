<?php

/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\OtelSdkBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class OtelSdkBundle extends Bundle
{
    public function getContainerExtension(): DependencyInjection\OtelSdkExtension
    {
        return new DependencyInjection\OtelSdkExtension();
    }
}

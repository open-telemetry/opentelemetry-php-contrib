<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Symfony\Integration\OtelSdkBundle\Mock;

class Sampler
{
    private string $foo;

    public function __construct(string $foo)
    {
        $this->foo = $foo;
    }

    public function getFoo()
    {
        return $this->foo;
    }
}

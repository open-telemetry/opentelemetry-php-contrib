<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\Fixtures;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route loader that counts how many times route resources are loaded.
 */
final class CountingRouteLoader extends Loader
{
    public int $loads = 0;

    public function __construct(private readonly RouteCollection $routes)
    {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $this->loads++;

        return $this->routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return true;
    }
}

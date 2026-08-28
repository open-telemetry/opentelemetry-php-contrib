<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Doctrine\Integration\Fixtures;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Minimal middleware that adds one delegating layer to the driver and to the
 * connection, reproducing the wrapper chain shipped by real-world middlewares.
 */
final class WrappingMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new WrappingDriver($driver);
    }
}

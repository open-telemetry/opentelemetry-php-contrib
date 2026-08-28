<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Doctrine\Integration\Fixtures;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class WrappingDriver extends AbstractDriverMiddleware
{
    /**
     * {@inheritDoc}
     */
    public function connect(array $params): DriverConnection
    {
        return new WrappingConnection(parent::connect($params));
    }
}

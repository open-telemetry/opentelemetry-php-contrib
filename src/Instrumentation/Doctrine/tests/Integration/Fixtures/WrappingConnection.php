<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Doctrine\Integration\Fixtures;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

final class WrappingConnection extends AbstractConnectionMiddleware
{
}

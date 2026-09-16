<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Phalcon\Integration\Fixtures;

use Phalcon\Mvc\Dispatcher;

/**
 * Simulates an app-level Dispatcher subclass that overrides callActionMethod()
 * and calls parent:: internally — the pattern that makes the underlying
 * hook() registration fire twice per real action (see PhalconInstrumentation's
 * class docblock).
 */
final class OverridingDispatcher extends Dispatcher
{
    public function callActionMethod($handler, string $actionMethod, array $params = [])
    {
        return parent::callActionMethod($handler, $actionMethod, $params);
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration;

use OpenTelemetry\TestUtils\TestState;
use Orchestra\Testbench\TestCase as BaseTestCase;

/** @psalm-suppress UnusedClass */
abstract class TestCase extends BaseTestCase
{
    use TestState;
}

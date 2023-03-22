<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Laravel;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}

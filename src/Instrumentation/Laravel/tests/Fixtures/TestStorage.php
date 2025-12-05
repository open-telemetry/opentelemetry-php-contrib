<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures;

use ArrayObject;

class TestStorage extends ArrayObject
{
    private static ?self $instance = null;

    public static function getInstance(): static
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance->exchangeArray([]);
    }
}

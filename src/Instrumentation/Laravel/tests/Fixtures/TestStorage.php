<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures;

use ArrayObject;

/**
 * @extends ArrayObject<int|string, mixed>
 */
class TestStorage extends ArrayObject
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new static();
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function reset(): void
    {
        self::$instance?->exchangeArray([]);
    }
}

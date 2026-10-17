<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\Exporters;

use ArrayObject;

final class InMemoryStorageManager
{
    private static ?ArrayObject $spans = null;
    private static ?ArrayObject $metrics = null;
    private static ?ArrayObject $logs = null;

    public static function metrics(): ArrayObject
    {
        return self::$metrics ??= new ArrayObject();
    }

    public static function logs(): ArrayObject
    {
        return self::$logs ??= new ArrayObject();
    }

    public static function spans(): ArrayObject
    {
        return self::$spans ??= new ArrayObject();
    }

    public static function reset(): void
    {
        self::logs()->exchangeArray([]);
        self::metrics()->exchangeArray([]);
        self::spans()->exchangeArray([]);
    }
}

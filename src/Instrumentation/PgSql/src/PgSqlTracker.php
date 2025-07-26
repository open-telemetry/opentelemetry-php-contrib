<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PgSql;

use PgSql\Connection;
use WeakMap;

final class PgSqlTracker
{
    /**
     * @var WeakMap<Connection, array<non-empty-string, bool|int|float|string|array|null>>
     */
    private WeakMap $connectionToAttributesMap;

    public function __construct()
    {
        /** @psalm-suppress PropertyTypeCoercion */
        $this->connectionToAttributesMap = new WeakMap();
    }

    /**
     * @param array<non-empty-string, bool|int|float|string|array|null> $attributes
     */
    public function trackConnectionAttributes(Connection $connection, array $attributes): void
    {
        $this->connectionToAttributesMap[$connection] = $attributes;
    }

    /**
     * @return array<non-empty-string, bool|int|float|string|array|null>
     */
    public function trackedAttributesForConnection(Connection $connection): array
    {
        /** @psalm-var array<non-empty-string, bool|int|float|string|array|null> */
        return $this->connectionToAttributesMap[$connection] ?? [];
    }
}

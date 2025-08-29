<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

interface SqlPropagatorInterface
{
    public static function inject(string $query, array $comments): string;
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

interface SqlInjectorInterface
{
    public static function inject(string $query, array $comments): string;
}

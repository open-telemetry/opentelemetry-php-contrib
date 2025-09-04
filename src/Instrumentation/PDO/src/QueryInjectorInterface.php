<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

interface QueryInjectorInterface
{
    public static function inject(string $query, array $comments): string;
}

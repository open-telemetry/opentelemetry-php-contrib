<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

class SqlServerPropagator implements SqlPropagatorInterface
{
    public static function inject(string $query, array $comments): string
    {
        // To-do: Implement SET_CONTEXT_INFO https://github.com/open-telemetry/semantic-conventions/pull/2363
        return $query;
    }
}

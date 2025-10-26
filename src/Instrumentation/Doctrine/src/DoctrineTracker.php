<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Doctrine;

use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\API\Trace\SpanContextInterface;
use WeakMap;

/**
 * @internal
 */
class DoctrineTracker
{
    public function __construct(
        private WeakMap $statementToSpanContextMap = new WeakMap(),
    ) {
    }

    public function trackStatement(Statement $statement, SpanContextInterface $context): void
    {
        $this->statementToSpanContextMap[$statement] = $context;
    }

    public function getSpanContextForStatement(Statement $statement): ?SpanContextInterface
    {
        return $this->statementToSpanContextMap[$statement] ?? null;
    }
}

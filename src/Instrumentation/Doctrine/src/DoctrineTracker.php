<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Doctrine;

use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\API\Trace\SpanContextInterface;
use WeakMap;
use WeakReference;

/**
 * @internal
 */
class DoctrineTracker
{
    private WeakMap $statementToSpanContextMap;

    public function __construct()
    {
        $this->statementToSpanContextMap = new WeakMap();
    }

    public function trackStatement(Statement $statement, SpanContextInterface $context): void
    {
        $this->statementToSpanContextMap[$statement] = WeakReference::create($context);
    }

    public function getSpanContextForStatement(Statement $statement): ?SpanContextInterface
    {
        return $this->statementToSpanContextMap[$statement]?->get();
    }
}

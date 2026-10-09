<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Events;

use OpenTelemetry\Context\ContextInterface;

class ContextObserved
{
    public function __construct(
        public readonly ContextInterface $context,
    ) {}
}

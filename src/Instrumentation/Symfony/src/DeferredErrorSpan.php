<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;

/** @internal */
final class DeferredErrorSpan
{
    private ?ScopeInterface $scope = null;
    private bool $ended = false;

    public function __construct(private readonly SpanInterface $span)
    {
    }

    public function activate(): void
    {
        if (!$this->ended && $this->scope === null) {
            $this->scope = Context::storage()->attach($this->span->storeInContext(Context::getCurrent()));
        }
    }

    public function end(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        if ($this->scope !== null && Context::storage()->scope() === $this->scope) {
            $this->scope->detach();
        }
        $this->scope = null;
        $this->span->end();
    }

    public function __destruct()
    {
        $this->end();
    }
}

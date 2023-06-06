<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Shim\OpenTracing;

use OpenTracing as API;

class Scope implements API\Scope
{
    private ScopeManager $scopeManager;

    private API\Span $wrapped;

    private bool $finishSpanOnClose;

    private ?Scope $toRestore = null;

    private bool $isClosed = false;

    private $restorer;

    public function __construct(
        ScopeManager $scopeManager,
        API\Span $wrapped,
        bool $finishSpanOnClose,
        ?Scope $toRestore,
        callable $restorer
    ) {
        $this->scopeManager = $scopeManager;
        $this->wrapped = $wrapped;
        $this->finishSpanOnClose = $finishSpanOnClose;
        $this->toRestore = $toRestore;
        $this->restorer = $restorer;
    }

    /**
     * {@inheritdoc}
     */
    public function getSpan(): API\Span
    {
        return $this->wrapped;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        if ($this->isClosed) {
            return;
        }

        if ($this->finishSpanOnClose) {
            $this->wrapped->finish();
        }

        $this->isClosed = true;
        if ($this->scopeManager->getActive() !== $this) {
            // This shouldn't happen if users call methods in expected order
            return;
        }

        if ($this->toRestore === null) {
            ($this->restorer)(null);

            return;
        }

        $toRestore = $this->toRestore;
        while (true) {
            // If the toRestore scope is already closed, we want to go up
            // to the previous level recursively until we get to the last
            // first one that is still open.
            if ($toRestore->isClosed) {
                $toRestore = $toRestore->toRestore;
            } else {
                break;
            }

            if ($toRestore === null) {
                ($this->restorer)(null);

                return;
            }
        }

        ($this->restorer)($toRestore);
    }
}

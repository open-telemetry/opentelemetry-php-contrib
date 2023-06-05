<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Shim\OpenTracing;

use OpenTracing as API;

class ScopeManager implements API\ScopeManager
{
    private ?Scope $active = null;

    /**
     * @inheritDoc
     */
    public function activate(API\Span $span, bool $finishSpanOnClose = API\ScopeManager::DEFAULT_FINISH_SPAN_ON_CLOSE): Scope
    {
        $restorer = function (?Scope $scope): void {
            $this->active = $scope;
        };

        $this->active = new Scope($this, $span, $finishSpanOnClose, $this->active, $restorer);

        return $this->active;
    }

    /**
     * @inheritDoc
     */
    public function getActive(): ?API\Scope
    {
        return $this->active;
    }
}

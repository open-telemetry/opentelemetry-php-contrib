<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Propagation\ServerTiming;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;

/**
 * This propagator type is used to inject the trace-context into HTTP responses.
 */
interface ResponsePropagator
{
    /**
     * Injects specific values from the provided {@see ContextInterface} into the provided carrier
     * via an {@see PropagationSetterInterface}.
     *
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/v1.6.1/specification/context/api-propagators.md#textmap-inject
     *
     * @param mixed $carrier
     */
    public function inject(&$carrier, PropagationSetterInterface $setter = null, ContextInterface $context = null): void;
}

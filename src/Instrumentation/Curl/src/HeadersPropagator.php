<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Curl;

use function assert;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;

/**
 * @internal
 */
class HeadersPropagator implements PropagationSetterInterface
{
    public static function instance(): self
    {
        static $instance;

        return $instance ??= new self();
    }

    public function set(&$carrier, string $key, string $value): void
    {
        assert($carrier instanceof CurlHandleMetadata);
        $carrier = $carrier->setHeaderToPropagate($key, $value);
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Psr18;

use function assert;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use Psr\Http\Message\RequestInterface;

/**
 * @internal
 */
enum HeadersPropagator implements PropagationSetterInterface
{
    case Instance;

    public function set(&$carrier, string $key, string $value): void
    {
        assert($carrier instanceof RequestInterface);

        $carrier = $carrier->withAddedHeader($key, $value);
    }
}

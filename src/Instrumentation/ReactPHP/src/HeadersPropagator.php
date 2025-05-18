<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\ReactPHP;

use function assert;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use Psr\Http\Message\RequestInterface;

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

    /** @param RequestInterface $carrier */
    public function set(&$carrier, string $key, string $value): void
    {
        assert($carrier instanceof RequestInterface);

        $carrier = $carrier->withAddedHeader($key, $value);
    }
}

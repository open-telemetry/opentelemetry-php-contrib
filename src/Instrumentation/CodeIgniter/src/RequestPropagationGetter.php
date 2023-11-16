<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\CodeIgniter;

use function assert;
use CodeIgniter\HTTP\MessageInterface;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;

/**
 * @internal
 */
final class RequestPropagationGetter implements PropagationGetterInterface
{
    public static function instance(): self
    {
        static $instance;

        return $instance ??= new self();
    }

    /** @psalm-suppress InvalidReturnType */
    public function keys($carrier): array
    {
        assert($carrier instanceof MessageInterface);

        return array_keys($carrier->headers());
    }

    public function get($carrier, string $key) : ?string
    {
        assert($carrier instanceof MessageInterface);

        return $carrier->getHeaderLine($key);
    }
}

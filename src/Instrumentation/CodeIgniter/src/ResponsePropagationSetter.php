<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\CodeIgniter;

use function assert;
use CodeIgniter\HTTP\MessageInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;

/**
 * @internal
 */
final class ResponsePropagationSetter implements PropagationSetterInterface
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

    public function set(&$carrier, string $key, string $value): void
    {
        assert($carrier instanceof MessageInterface);

        $carrier->setHeader($key, $value);
    }
}

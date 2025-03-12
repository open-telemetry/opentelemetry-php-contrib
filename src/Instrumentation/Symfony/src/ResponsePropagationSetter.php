<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use function assert;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use Symfony\Component\HttpFoundation\Response;

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

    /** @psalm-suppress InvalidReturnType
     *  @psalm-suppress PossiblyUnusedMethod
     */
    public function keys($carrier): array
    {
        assert($carrier instanceof Response);

        /** @psalm-suppress InvalidReturnStatement */
        return $carrier->headers->keys();
    }

    public function set(&$carrier, string $key, string $value): void
    {
        assert($carrier instanceof Response);

        $carrier->headers->set($key, $value);
    }
}

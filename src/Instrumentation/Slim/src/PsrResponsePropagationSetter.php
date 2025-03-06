<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Slim;

use function assert;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 * @todo when response propagation spec is accepted, this can move into core as a generic PSR-7 implementation
 */
final class PsrResponsePropagationSetter implements PropagationSetterInterface
{
    public static function instance(): self
    {
        static $instance;

        return $instance ??= new self();
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function keys($carrier): array
    {
        assert($carrier instanceof ResponseInterface);

        return array_keys($carrier->getHeaders());
    }

    public function set(&$carrier, string $key, string $value): void
    {
        assert($carrier instanceof ResponseInterface);

        $carrier = $carrier->withHeader($key, $value);
    }
}

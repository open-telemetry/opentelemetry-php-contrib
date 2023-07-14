<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\OtelBundle\HttpKernel;

use function count;
use function implode;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
final class HeadersPropagator implements PropagationGetterInterface
{
    /**
     * @param Request $carrier
     *
     * @psalm-suppress LessSpecificImplementedReturnType
     */
    public function keys($carrier): array
    {
        return $carrier->headers->keys();
    }

    /**
     * @param Request $carrier
     */
    public function get($carrier, string $key): ?string
    {
        /** @psalm-suppress InvalidArgument */
        return count($carrier->headers->all($key)) > 1
            ? implode(',', $carrier->headers->all($key))
            : $carrier->headers->get($key);
    }
}

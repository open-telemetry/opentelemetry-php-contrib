<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\OtelBundle\HttpKernel;

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
        $result = null;
        foreach ($carrier->headers->all($key) as $value) {
            if ($value === null) {
                continue;
            }
            if ($result === null) {
                $result = $value;
            } else {
                $result .= ',' . $value;
            }
        }

        return $result;
    }
}

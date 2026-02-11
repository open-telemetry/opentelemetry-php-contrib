<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Yii;

use function assert;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use yii\web\Request;

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
    #[\Override]
    public function keys($carrier): array
    {
        assert($carrier instanceof Request);

        return array_keys($carrier->getHeaders()->toArray());
    }

    #[\Override]
    public function get($carrier, string $key): ?string
    {
        assert($carrier instanceof Request);

        // When $first=true (3rd param), get() returns string|null, not an array
        return $carrier->getHeaders()->get($key, null, true);
    }
}

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
    public function keys($carrier): array
    {
        assert($carrier instanceof Request);

        return array_keys($carrier->getHeaders()->toArray());
    }

    public function get($carrier, string $key) : ?string
    {
        assert($carrier instanceof Request);

        $result = $carrier->getHeaders()->get($key, null, true);

        if (is_array($result)) {
            return (string) array_values($result)[0];
        }

        return $result;
        
    }
}

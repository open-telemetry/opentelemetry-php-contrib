<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Yii;

use function assert;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use yii\web\Response;

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
        assert($carrier instanceof Response);

        return array_keys($carrier->getHeaders()->toArray());
    }

    public function set(&$carrier, string $key, string $value): void
    {
        assert($carrier instanceof Response);

        $carrier->getHeaders()->set($key, $value);
    }
}

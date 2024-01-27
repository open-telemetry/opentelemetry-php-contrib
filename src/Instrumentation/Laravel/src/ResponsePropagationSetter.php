<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use function assert;
use Illuminate\Http\Response;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;

/**
 * @internal
 */
class ResponsePropagationSetter implements PropagationSetterInterface
{
    public static function instance(): self
    {
        static $instance;

        return $instance ??= new self();
    }

    public function set(&$carrier, string $key, string $value): void
    {
        assert($carrier instanceof Response);

        $carrier->headers->set($key, $value);
    }
}

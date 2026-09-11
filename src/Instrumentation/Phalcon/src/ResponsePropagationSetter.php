<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Phalcon;

use function assert;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use Phalcon\Http\ResponseInterface;

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

    public function set(&$carrier, string $key, string $value): void
    {
        assert($carrier instanceof ResponseInterface);

        $carrier->setHeader($key, $value);
    }
}

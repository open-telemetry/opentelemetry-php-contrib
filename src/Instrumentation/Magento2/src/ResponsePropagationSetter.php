<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Magento2;

use function assert;
use Magento\Framework\App\Response\Http as HttpResponse;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use Override;

final class ResponsePropagationSetter implements PropagationSetterInterface
{
    private static ?self $instance = null;

    /** @psalm-external-mutation-free */
    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    #[Override]
    public function set(mixed &$carrier, string $key, string $value): void
    {
        assert($carrier instanceof HttpResponse);

        $carrier = $carrier->setHeader($key, $value);
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Context\Swoole;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\Context\ScopeInterface;

/**
 * @internal
 */
final class SwooleContextScope implements ScopeInterface, ContextStorageScopeInterface
{
    private ContextStorageScopeInterface $scope;
    private SwooleContextHandler $handler;

    public function __construct(ContextStorageScopeInterface $scope, SwooleContextHandler $handler)
    {
        $this->scope = $scope;
        $this->handler = $handler;
    }

    #[\Override]
    public function offsetExists($offset): bool
    {
        return $this->scope->offsetExists($offset);
    }

    /**
     * @phan-suppress PhanUndeclaredClassAttribute
     */
    #[\Override]
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->scope->offsetGet($offset);
    }

    #[\Override]
    public function offsetSet($offset, $value): void
    {
        $this->scope->offsetSet($offset, $value);
    }

    #[\Override]
    public function offsetUnset($offset): void
    {
        $this->scope->offsetUnset($offset);
    }

    #[\Override]
    public function context(): ContextInterface
    {
        return $this->scope->context();
    }

    #[\Override]
    public function detach(): int
    {
        $this->handler->switchToActiveCoroutine();

        return $this->scope->detach();
    }
}

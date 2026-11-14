<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\Fixtures;

use Psr\Container\ContainerInterface;

/**
 * Minimal container building each service when it is fetched, so a service
 * may depend on one created after the container.
 */
final class LazyContainer implements ContainerInterface
{
    /**
     * @param array<string, callable(): mixed> $factories
     */
    public function __construct(
        private array $factories,
    ) {
    }

    public function get(string $id): mixed
    {
        return ($this->factories[$id])();
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }
}

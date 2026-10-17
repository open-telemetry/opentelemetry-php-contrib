<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils;

use ArrayObject;
use OpenTelemetry\TestUtils\Exporters\InMemoryStorageManager;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

trait TestState
{
    private array $otelEnvironmentVariables = [];
    private array $otelServerVariables = [];

    protected ArrayObject $logStorage;
    protected ArrayObject $metricsStorage;
    protected ArrayObject $spanStorage;

    #[Before]
    public function setUpInMemoryStorageManager(): void
    {
        $this->logStorage = InMemoryStorageManager::logs();
        $this->metricsStorage = InMemoryStorageManager::metrics();
        $this->spanStorage = InMemoryStorageManager::spans();
    }

    #[After]
    protected function tearDownInMemoryStorageManagerState(): void
    {
        InMemoryStorageManager::reset();
    }

    #[After]
    protected function restoreEnvironmentVariables(): void
    {
        foreach ($this->otelEnvironmentVariables as $variable => $value) {
            putenv(false === $value ? $variable : "{$variable}={$value}");
        }
        foreach ($this->otelServerVariables as $variable => $value) {
            if ($value === null) {
                unset($_SERVER[$variable]);
            } else {
                $_SERVER[$variable] = $value;
            }
        }
    }

    /**
     * A real environment variable is visible through both `getenv()` and `$_SERVER`, so both are
     * set here: `putenv()` alone is invisible to anything reading `$_SERVER`, such as
     * {@see \OpenTelemetry\Config\SDK\Configuration\Environment\ServerEnvSource}.
     *
     * @psalm-suppress InvalidArrayOffset
     */
    protected function setEnvironmentVariable(string $variable, mixed $value): void
    {
        if (!isset($this->otelEnvironmentVariables[$variable])) {
            $this->otelEnvironmentVariables[$variable] = getenv($variable);
        }
        if (!array_key_exists($variable, $this->otelServerVariables)) {
            $this->otelServerVariables[$variable] = $_SERVER[$variable] ?? null;
        }

        putenv(null === $value ? $variable : "{$variable}={$value}");
        if (null === $value) {
            unset($_SERVER[$variable]);
        } else {
            $_SERVER[$variable] = $value;
        }
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use OpenTelemetry\SDK\Common\Configuration\Configuration;

final class InstrumentationConfig
{
    public const HTTP = 'http';
    public const CONSOLE = 'console';
    public const QUEUE = 'queue';
    public const ELOQUENT = 'eloquent';
    public const SERVE = 'serve';
    public const CACHE = 'cache';
    public const DB = 'db';
    public const HTTP_CLIENT = 'http-client';
    public const EXCEPTION = 'exception';
    public const LOG = 'log';
    public const REDIS = 'redis';

    private const GROUP_WATCHERS = [
        self::CACHE,
        self::DB,
        self::HTTP_CLIENT,
        self::EXCEPTION,
        self::LOG,
        self::REDIS,
    ];

    private const ALL_INSTRUMENTATIONS = [
        self::HTTP,
        self::CONSOLE,
        self::QUEUE,
        self::ELOQUENT,
        self::SERVE,
        self::CACHE,
        self::DB,
        self::HTTP_CLIENT,
        self::EXCEPTION,
        self::LOG,
        self::REDIS,
    ];

    private const ALIASES = [
        'all' => self::ALL_INSTRUMENTATIONS,
        'watchers' => self::GROUP_WATCHERS,
    ];

    private const ENV_ENABLED = 'OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS';
    private const ENV_DISABLED = 'OTEL_LARAVEL_DISABLED_INSTRUMENTATIONS';

    private static ?self $instance = null;

    /** @var array<string> */
    private array $enabledInstrumentations;

    private function __construct()
    {
        $this->enabledInstrumentations = $this->resolveEnabledInstrumentations();
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function isInstrumentationEnabled(string $name): bool
    {
        return in_array($name, $this->enabledInstrumentations, true);
    }

    public function hasAnyWatcherEnabled(): bool
    {
        return !empty(array_intersect(self::GROUP_WATCHERS, $this->enabledInstrumentations));
    }

    /**
     * @return array<string>
     */
    private function resolveEnabledInstrumentations(): array
    {
        $enabled = $this->expandAliases($this->getConfigList(self::ENV_ENABLED));
        $disabled = $this->expandAliases($this->getConfigList(self::ENV_DISABLED));

        return array_values(array_diff($enabled ?: self::ALL_INSTRUMENTATIONS, $disabled));
    }

    /**
     * @param array<string> $names
     * @return array<string>
     */
    private function expandAliases(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $name = trim($name);
            $result = array_merge($result, self::ALIASES[$name] ?? ($name !== '' ? [$name] : []));
        }

        return array_unique($result);
    }

    /**
     * @return array<string>
     */
    private function getConfigList(string $key): array
    {
        if (class_exists(Configuration::class)) {
            return Configuration::getList($key, []);
        }

        $value = $_ENV[$key] ?? getenv($key);

        return ($value !== false && $value !== '') ? explode(',', $value) : [];
    }

    /**
     * Reset the singleton instance (for testing purposes).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}

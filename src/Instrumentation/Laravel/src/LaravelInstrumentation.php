<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Configuration\ConfigProperties;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;

class LaravelInstrumentation implements Instrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.laravel';

    /** @psalm-suppress PossiblyUnusedMethod */
    #[\Override]
    public function register(HookManagerInterface $hookManager, ConfigProperties $configuration, Context $context): void
    {
        $config = $configuration->get(LaravelConfiguration::class) ?? new LaravelConfiguration();

        if (! $config->enabled) {
            return;
        }

        foreach (ServiceLoader::load(Hook::class) as $hook) {
            /** @var Hook $hook */
            $hook->instrument($config, $hookManager, $context);
        }
    }

    public static function buildProviderName(string ...$component): string
    {
        return implode('.', [
            self::INSTRUMENTATION_NAME,
            ...$component,
        ]);
    }

    /**
     * Trace context (and, in future, baggage) is only injected into outbound HTTP client requests
     * when explicitly opted into, since the target of those requests may be a third-party service
     * outside the application's control that should not receive internal trace/baggage data.
     */
    public static function shouldPropagateHttpClientTraceContext(): bool
    {
        return class_exists(Configuration::class)
            && Configuration::getBoolean('OTEL_PHP_INSTRUMENTATION_LARAVEL_HTTP_CLIENT_PROPAGATION_ENABLED', false);
    }
}

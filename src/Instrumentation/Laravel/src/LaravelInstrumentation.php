<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Configuration\ConfigProperties;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context as InstrumentationContext;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpConfig;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;

class LaravelInstrumentation implements Instrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.laravel';

    /** @psalm-suppress PossiblyUnusedMethod */
    #[\Override]
    public function register(HookManagerInterface $hookManager, ConfigProperties $configuration, InstrumentationContext $context): void
    {
        $laravelConfiguration = $configuration->get(LaravelConfiguration::class) ?? new LaravelConfiguration();

        if (! $laravelConfiguration->enabled) {
            return;
        }

        $laravelConfiguration->setHttpConfig(
            $configuration->get(HttpConfig::class) ?? new HttpConfig(),
        );

        foreach (ServiceLoader::load(Hook::class) as $hook) {
            /** @var Hook $hook */
            $hook->instrument($laravelConfiguration, $hookManager, $context);
        }
    }

    public static function buildProviderName(string ...$component): string
    {
        return implode('.', [
            self::INSTRUMENTATION_NAME,
            ...$component,
        ]);
    }
}

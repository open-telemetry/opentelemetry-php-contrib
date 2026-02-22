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
    public function register(HookManagerInterface $hookManager, ConfigProperties $configuration, Context $context): void
    {
        $config = $configuration->get(LaravelConfiguration::class) ?? new LaravelConfiguration();
        var_dump($config);

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
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\ConfigurationRegistry;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManager;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\API\Instrumentation\ConfigurationResolver;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\SemConv\Version;

class LaravelInstrumentation implements Instrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.laravel';

    public function register(HookManager $hookManager, ConfigurationRegistry $configuration, Context $context): void
    {
        $config = $configuration->get(LaravelConfiguration::class) ?? LaravelConfiguration::default();

        if (! $config->enabled) {
            return;
        }

        $logger = $context->loggerProvider->getLogger(self::INSTRUMENTATION_NAME, schemaUrl: Version::VERSION_1_25_0->url());
        $meter = $context->meterProvider->getMeter(self::INSTRUMENTATION_NAME, schemaUrl: Version::VERSION_1_25_0->url());
        $tracer = $context->tracerProvider->getTracer(self::INSTRUMENTATION_NAME, schemaUrl: Version::VERSION_1_25_0->url());

        foreach (ServiceLoader::load(Hook::class) as $hook) {
            /** @var Hook $hook */
            $hook->instrument($hookManager, $config, $logger, $meter, $tracer);
        }
    }

    public static function shouldTraceCli(): bool
    {
        return PHP_SAPI !== 'cli' || (new ConfigurationResolver())->getBoolean('OTEL_PHP_TRACE_CLI_ENABLED');
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelBundle;

use function class_exists;
use Composer\InstalledVersions;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Symfony\OtelBundle\DependencyInjection\Compiler\SetAliasIfNotDefinedCompilerPass;
use OutOfBoundsException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class OtelBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new SetAliasIfNotDefinedCompilerPass(TextMapPropagatorInterface::class, NoopTextMapPropagator::class));
        $container->addCompilerPass(new SetAliasIfNotDefinedCompilerPass(TracerProviderInterface::class, NoopTracerProvider::class));
        $container->addCompilerPass(new SetAliasIfNotDefinedCompilerPass(MeterProviderInterface::class, NoopMeterProvider::class));
    }

    public static function instrumentationName(): string
    {
        return 'open-telemetry/contrib-symfony-instrumentation-bundle';
    }

    public static function instrumentationVersion(): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            return InstalledVersions::getPrettyVersion(self::instrumentationName());
        } catch (OutOfBoundsException $e) {
            return null;
        }
    }
}

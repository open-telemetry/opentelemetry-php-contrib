<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Context\Revolt;

use Composer\InstalledVersions;
use Nevay\SPI\ServiceProviderDependency\PackageDependency;
use OpenTelemetry\API\Configuration\ConfigProperties;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\API\Metrics\ObserverInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;
use function strtolower;

/**
 * @experimental
 */
#[PackageDependency('open-telemetry/api', '^1.1')]
final class RevoltMetrics implements Instrumentation
{
    public function register(HookManagerInterface $hookManager, ConfigProperties $configuration, Context $context): void
    {
        $meter = $context->meterProvider->getMeter(
            name: 'io.opentelemetry.contrib.php.revolt-adapter',
            version: InstalledVersions::getPrettyVersion('open-telemetry/opentelemetry-revolt-adapter'),
        );

        $meter->createObservableUpDownCounter(
            name: 'php.revolt.eventloop.callbacks',
            unit: '{callback}',
            description: 'The number of registered event loop callbacks',
            _: static function (ObserverInterface $observer): void {
                /** @var array<string, array{array{int, int}, array{int, int}}> $callbacks */
                $callbacks = [];
                foreach (EventLoop::getIdentifiers() as $identifier) {
                    $type = EventLoop::getType($identifier);
                    $enabled = +EventLoop::isEnabled($identifier);
                    $referenced = +EventLoop::isReferenced($identifier);

                    $callbacks[$type->name][$enabled][$referenced] ??= 0;
                    $callbacks[$type->name][$enabled][$referenced]++;
                }

                foreach (CallbackType::cases() as $type) {
                    $er = $callbacks[$type->name][1][1] ?? 0;
                    $eu = $callbacks[$type->name][1][0] ?? 0;
                    $dr = $callbacks[$type->name][0][1] ?? 0;
                    $du = $callbacks[$type->name][0][0] ?? 0;

                    $t = strtolower($type->name);
                    $observer->observe($er, ['php.revolt.eventloop.callback.type' => $t, 'php.revolt.eventloop.callback.state' => 'referenced']);
                    $observer->observe($eu, ['php.revolt.eventloop.callback.type' => $t, 'php.revolt.eventloop.callback.state' => 'unreferenced']);
                    $observer->observe($dr + $du, ['php.revolt.eventloop.callback.type' => $t, 'php.revolt.eventloop.callback.state' => 'disabled']);
                }
            },
        );
    }
}

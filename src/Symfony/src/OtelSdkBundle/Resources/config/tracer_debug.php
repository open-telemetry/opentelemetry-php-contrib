<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Resources;

use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\DataCollector\OtelDataCollector;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Debug\TraceableSpanProcessor;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Debug\TraceableTracerProvider;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Util\ConfigHelper;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container) {
    $container->services()
        ->set('debug.open_telemetry.sdk.trace.tracer_provider', TraceableTracerProvider::class)
        ->decorate('open_telemetry.sdk.trace.tracer_provider', null, 255)
        ->args([
            ConfigHelper::createReference('debug.open_telemetry.sdk.trace.tracer_provider.inner'),
            ConfigHelper::createReference('otel.data_collector'),
        ])

        ->set('debug.open_telemetry.sdk.trace.span_processor.traceable', TraceableSpanProcessor::class)
        ->args([
            ConfigHelper::createReference('open_telemetry.sdk.trace.span_processor.default'),
            ConfigHelper::createReference('otel.data_collector'),
        ])

        ->set('otel.data_collector', OtelDataCollector::class)
        ->tag('data_collector', [
            'template' => '@OtelSdk/Collector/otel.html.twig',
            'id' => 'otel',
        ])
    ;
};

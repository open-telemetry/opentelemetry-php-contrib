<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\OtelSdkBundle\DependencyInjection;

interface ConfigMappings
{
    public const SAMPLERS = [
        Configuration::ALWAYS_ON_SAMPLER => Samplers::ALWAYS_ON,
        Configuration::ALWAYS_OFF_SAMPLER => Samplers::ALWAYS_OFF,
        Configuration::TRACE_ID_RATIO_SAMPLER => Samplers::TRACE_ID_RATIO_BASED,
        Configuration::PARENT_BASED_SAMPLER => Samplers::PARENT_BASED,
    ];
    public const SPAN_PROCESSORS = [
        Configuration::SIMPLE_PROCESSOR => SpanProcessors::SIMPLE,
        Configuration::BATCH_PROCESSOR => SpanProcessors::BATCH,
        Configuration::NOOP_PROCESSOR => SpanProcessors::NOOP,
        Configuration::MULTI_PROCESSOR => SpanProcessors::MULTI,
    ];
    public const SPAN_EXPORTERS = [
        Configuration::ZIPKIN_EXPORTER => SpanExporters::ZIPKIN,
        Configuration::OTLP_HTTP_EXPORTER => SpanExporters::OTLP_HTTP,
        Configuration::OTLP_GRPC_EXPORTER => SpanExporters::OTLP_GRPC,
    ];
    public const SPAN_EXPORTER_FACTORIES = [
        Configuration::ZIPKIN_EXPORTER_FACTORY => SpanExporterFactories::ZIPKIN,
        Configuration::OTLP_EXPORTER_FACTORY => SpanExporterFactories::OTLP,
    ];
}

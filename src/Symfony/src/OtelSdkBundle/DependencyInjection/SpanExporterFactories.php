<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection;

use OpenTelemetry\Contrib;

interface SpanExporterFactories
{
    public const ZIPKIN = Contrib\Zipkin\SpanExporterFactory::class;
    public const NEWRELIC = Contrib\Newrelic\SpanExporterFactory::class;
    public const OTLP = Contrib\Otlp\SpanExporterFactory::class;
    public const SPAN_EXPORTER_FACTORIES = [
        self::ZIPKIN,
        self::NEWRELIC,
        self::OTLP,
    ];
    public const NAMESPACE = 'OpenTelemetry\SDK\Trace\SpanExporter';
}

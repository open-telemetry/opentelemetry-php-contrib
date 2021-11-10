<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection;

use OpenTelemetry\Contrib;

interface SpanExporters
{
    public const JAEGER = Contrib\Jaeger\Exporter::class;
    public const ZIPKIN = Contrib\Zipkin\Exporter::class;
    public const NEWRELIC = Contrib\Newrelic\Exporter::class;
    public const OTLP_HTTP = Contrib\OtlpHttp\Exporter::class;
    public const OTLP_GRPC = Contrib\OtlpGrpc\Exporter::class;
    public const ZIPKIN_TO_NEWRELIC = Contrib\ZipkinToNewrelic\Exporter::class;
    public const SPAN_EXPORTERS = [
        self::JAEGER,
        self::ZIPKIN,
        self::NEWRELIC,
        self::OTLP_HTTP,
        self::OTLP_GRPC,
        self::ZIPKIN_TO_NEWRELIC,
    ];
    public const NAMESPACE = 'OpenTelemetry\SDK\Trace\SpanExporter';
}

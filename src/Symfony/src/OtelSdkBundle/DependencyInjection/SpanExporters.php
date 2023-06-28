<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection;

use OpenTelemetry\Contrib;

/** @phan-file-suppress PhanUndeclaredClassReference */
interface SpanExporters
{
    public const ZIPKIN = Contrib\Zipkin\Exporter::class;
    public const OTLP_HTTP = Contrib\OtlpHttp\Exporter::class;
    public const OTLP_GRPC = Contrib\OtlpGrpc\Exporter::class;
    public const SPAN_EXPORTERS = [
        self::ZIPKIN,
        self::OTLP_HTTP,
        self::OTLP_GRPC,
    ];
    public const NAMESPACE = 'OpenTelemetry\SDK\Trace\SpanExporter';
}

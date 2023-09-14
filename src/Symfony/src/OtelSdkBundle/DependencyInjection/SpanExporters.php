<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\OtelSdkBundle\DependencyInjection;

use OpenTelemetry\Contrib;

/** @phan-file-suppress PhanUndeclaredClassReference */
interface SpanExporters
{
    public const ZIPKIN = Contrib\Zipkin\Exporter::class;
    public const OTLP = Contrib\Otlp\SpanExporter::class;
    public const SPAN_EXPORTERS = [
        self::ZIPKIN,
        self::OTLP,
    ];
    public const NAMESPACE = 'OpenTelemetry\SDK\Trace\SpanExporter';
}

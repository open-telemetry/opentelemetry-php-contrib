<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Symfony\Integration\OtelSdkBundle\Mock;

use OpenTelemetry\SDK\Trace\SpanExporter\SpanExporterFactoryInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

class SpanExporterFactory implements SpanExporterFactoryInterface
{
    public function create(): SpanExporterInterface
    {
        return new SpanExporter();
    }

    public function build(array $options = []): SpanExporterInterface
    {
        return new SpanExporter();
    }
}

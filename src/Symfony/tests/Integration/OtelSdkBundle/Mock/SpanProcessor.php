<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Symfony\Integration\OtelSdkBundle\Mock;

use OpenTelemetry\SDK\Trace\SpanExporterInterface;

class SpanProcessor
{
    private ?SpanExporterInterface $exporter;
    private ?string $foo;

    public function __construct(?SpanExporterInterface $exporter, ?string $foo)
    {
        $this->exporter = $exporter;
        $this->foo = $foo;
    }

    public function getExporter(): ?SpanExporterInterface
    {
        return $this->exporter;
    }

    public function getFoo(): ?string
    {
        return $this->foo;
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Integration\Symfony\OtelSdkBundle\Mock;

use OpenTelemetry\SDK\Trace\SpanExporterInterface;

class SpanExporter implements SpanExporterInterface
{
    private ?string $logFile;

    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile;
    }


    public static function fromConnectionString(string $endpointUrl, string $name, string $args): self
    {
        return new self();
    }

    public function export(iterable $spans): int
    {
        return 1;
    }

    public function shutdown(): bool
    {
        return true;
    }

    public function forceFlush(): bool
    {
        return true;
    }

    public function getLogFile(): ?string
    {
        return $this->logFile;
    }
}

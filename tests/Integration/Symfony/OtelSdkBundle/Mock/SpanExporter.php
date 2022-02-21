<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Integration\Symfony\OtelSdkBundle\Mock;

use OpenTelemetry\SDK\Trace\SpanExporterInterface;

class SpanExporter implements SpanExporterInterface
{
    private ?string $logFile;
    private ?string $serviceName;

    public function __construct(?string $serviceName = null, ?string $logFile = null)
    {
        $this->serviceName = $serviceName;
        $this->logFile = $logFile;
    }

    public static function fromConnectionString(string $endpointUrl, string $name, string $args): self
    {
        return new self($name, $args);
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

    public function getServiceName(): ?string
    {
        return $this->serviceName;
    }

    public function getLogFile(): ?string
    {
        return $this->logFile;
    }
}

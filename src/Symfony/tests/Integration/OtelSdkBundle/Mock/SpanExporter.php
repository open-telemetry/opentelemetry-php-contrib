<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Symfony\Integration\OtelSdkBundle\Mock;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
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

    public function export(iterable $spans, ?CancellationInterface $cancellation = null): FutureInterface
    {
        return new CompletedFuture(1);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
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

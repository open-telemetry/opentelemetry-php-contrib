<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Exporter\Instana;

use OpenTelemetry\SDK\Trace\SpanExporter\SpanExporterFactoryInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * Class InstanaSpanExporter - implements the export Factory interface for data transfer via Instana Transport
 * @psalm-api
 */
class SpanExporterFactory implements SpanExporterFactoryInterface
{
    const DEFAULT_INSTANA_AGENT_HOST = '127.0.0.1';
    const DEFAULT_INSTANA_AGENT_PORT = '42699';

    /**
    * @suppress PhanUndeclaredClassAttribute
    */
    #[\Override]
    public function create(): SpanExporterInterface
    {
        $host = $_SERVER['INSTANA_AGENT_HOST'] ?? self::DEFAULT_INSTANA_AGENT_HOST;
        $port = $_SERVER['INSTANA_AGENT_PORT'] ?? self::DEFAULT_INSTANA_AGENT_PORT;

        $endpoint = $host . ':' . $port;
        $timeout = 10; //s
        $attempts = 1;

        $transport = new InstanaTransport($endpoint, $timeout, $attempts);

        $uuid = $transport->getUuid();
        $pid = $transport->getPid();
        $converter = new SpanConverter($uuid, $pid);

        return new SpanExporter($transport, $converter);
    }
}

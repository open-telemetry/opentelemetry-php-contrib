<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Logs\Monolog;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Common\Instrumentation\Globals;
use OpenTelemetry\API\Logs as API;

class Handler extends AbstractHandler
{
    private API\LoggerInterface $logger;
    private NormalizerFormatter $normalizer;

    /**
     * @psalm-suppress ArgumentTypeCoercion
     */
    public function __construct(API\LoggerProviderInterface $loggerProvider = null, int|string|Level $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->normalizer = new NormalizerFormatter();
        $loggerProvider ??= Globals::loggerProvider();
        $this->logger = $loggerProvider->getLogger('monolog');
    }

    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }
        $normalized = $this->normalizer->format($record);
        $logRecord = (new API\LogRecord())
            ->setTimestamp($record->datetime->getTimestamp() * API\LogRecord::NANOS_PER_SECOND)
            ->setSeverityNumber(API\Map\Psr3::severityNumber($record->level->toPsrLogLevel()))
            ->setSeverityText($record->level->toPsrLogLevel())
            ->setBody($normalized['message'])
            ->setAttributes(array_merge($normalized['context'], $normalized['extra']))
        ;
        $this->logger->logRecord($logRecord);

        return $this->bubble === false;
    }

    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }

    public function close(): void
    {
    }
}

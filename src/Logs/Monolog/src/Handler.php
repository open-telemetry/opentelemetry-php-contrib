<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Logs\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use OpenTelemetry\API\Logs as API;

class Handler extends AbstractProcessingHandler
{
    private API\LoggerInterface $logger;

    /**
     * @psalm-suppress InvalidArgument
     */
    public function __construct(API\LoggerProviderInterface $loggerProvider, $level, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->logger = $loggerProvider->getLogger('monolog');
    }

    /**
     * @phan-suppress PhanTypeMismatchArgument
     * @psalm-suppress InvalidOperand
     */
    protected function write($record): void
    {
        $logRecord = (new API\LogRecord())
            ->setTimestamp((int) ($record['datetime']->format('Uu') * 1000))
            ->setSeverityNumber(API\Map\Psr3::severityNumber($record['level_name']))
            ->setSeverityText($record['level_name'])
            ->setBody($record['message'])
            ->setAttributes($record['context'] + $record['extra'])
        ;
        $this->logger->logRecord($logRecord);
    }
}

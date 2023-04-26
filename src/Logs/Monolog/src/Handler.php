<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Logs\Monolog;

use function count;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\NormalizerFormatter;
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

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new NormalizerFormatter();
    }

    /**
     * @phan-suppress PhanTypeMismatchArgument
     * @psalm-suppress InvalidOperand
     */
    protected function write($record): void
    {
        $formatted = $record['formatted'];
        $logRecord = (new API\LogRecord())
            ->setTimestamp((int) $record['datetime']->format('Uu') * 1000)
            ->setSeverityNumber(API\Map\Psr3::severityNumber($record['level_name']))
            ->setSeverityText($record['level_name'])
            ->setBody($formatted['message'])
            ->setAttribute('channel', $record['channel'])
        ;
        foreach (['context', 'extra'] as $key) {
            if (isset($formatted[$key]) && count($formatted[$key]) > 0) {
                $logRecord->setAttribute($key, $formatted[$key]);
            }
        }
        $this->logger->emit($logRecord);
    }
}

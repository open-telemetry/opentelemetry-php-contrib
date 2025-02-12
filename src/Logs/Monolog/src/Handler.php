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
    /** @var API\LoggerInterface[] */
    private array $loggers = [];
    private API\LoggerProviderInterface $loggerProvider;

    /**
     * @psalm-suppress InvalidArgument
     */
    public function __construct(API\LoggerProviderInterface $loggerProvider, $level, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->loggerProvider = $loggerProvider;
    }

    protected function getLogger(string $channel): API\LoggerInterface
    {
        if (!array_key_exists($channel, $this->loggers)) {
            $this->loggers[$channel] = $this->loggerProvider->getLogger($channel);
        }

        return $this->loggers[$channel];
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
            ->setSeverityNumber(API\Severity::fromPsr3($record['level_name']))
            ->setSeverityText($record['level_name'])
            ->setBody($formatted['message'])
        ;
        foreach (['context', 'extra'] as $key) {
            if (isset($formatted[$key]) && count($formatted[$key]) > 0) {
                $logRecord->setAttribute($key, $formatted[$key]);
            }
        }
        $this->getLogger($record['channel'])->emit($logRecord);
    }
}

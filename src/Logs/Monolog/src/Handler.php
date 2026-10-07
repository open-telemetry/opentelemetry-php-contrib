<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Logs\Monolog;

use function count;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use OpenTelemetry\API\Logs as API;
use Throwable;

class Handler extends AbstractProcessingHandler
{
    public const OTEL_PHP_MONOLOG_ATTRIB_MODE = 'OTEL_PHP_MONOLOG_ATTRIB_MODE';
    public const MODE_PSR3 = 'psr3';
    public const MODE_OTEL = 'otel';
    public const MODES = [
        self::MODE_PSR3,
        self::MODE_OTEL,
    ];
    public const DEFAULT_MODE = self::MODE_PSR3;
    private string $mode;

    /** @var API\LoggerInterface[] */
    private array $loggers = [];
    private API\LoggerProviderInterface $loggerProvider;
    private ?FormatterInterface $formatterInterface;

    /**
     * @psalm-suppress InvalidArgument
     */
    public function __construct(API\LoggerProviderInterface $loggerProvider, $level, bool $bubble = true, ?FormatterInterface $formatterInterface = null, HandlerConfiguration $config = new HandlerConfiguration())
    {
        parent::__construct($level, $bubble);
        $this->loggerProvider = $loggerProvider;
        $this->formatterInterface = $formatterInterface;
        $this->mode = $config->mode;
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
        return $this->formatterInterface ?? new NormalizerFormatter();
    }

    protected function write($record): void
    {
        $formatted = $record['formatted'];
        $recordBuilder = $this->getLogger($record['channel'])->logRecordBuilder()
            ->setTimestamp((int) $record['datetime']->format('Uu') * 1000)
            ->setSeverityNumber(API\Severity::fromPsr3($record['level_name']))
            ->setSeverityText($record['level_name'])
            ->setBody($formatted['message'])
        ;

        foreach (['context', 'extra'] as $key) {
            if ($this->mode === self::MODE_PSR3 && isset($formatted[$key]) && count($formatted[$key]) > 0) {
                $recordBuilder->setAttribute($key, $formatted[$key]);
            }
            if (isset($record[$key]) && $record[$key] !== []) {
                foreach ($record[$key] as $attributeName => $attribute) {
                    if (
                        $key === 'context'
                        && $attributeName === 'exception'
                        && $attribute instanceof Throwable
                    ) {
                        $recordBuilder->setException($attribute);

                        continue;
                    }
                    switch ($this->mode) {
                        case self::MODE_PSR3:
                            $recordBuilder->setAttribute(sprintf('%s.%s', $key, $attributeName), $attribute);

                            break;
                        case self::MODE_OTEL:
                            $recordBuilder->setAttribute($attributeName, $attribute);

                            break;
                    }
                }
            }
        }
        $recordBuilder->emit();
    }

}

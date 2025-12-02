<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Logs\Monolog;

use function count;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use OpenTelemetry\API\Instrumentation\ConfigurationResolver;
use OpenTelemetry\API\Logs as API;
use OpenTelemetry\SDK\Common\Exception\StackTraceFormatter;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;

use Throwable;

class Handler extends AbstractProcessingHandler
{
    public const OTEL_PHP_MONOLOG_ATTRIB_MODE = 'OTEL_PHP_MONOLOG_ATTRIB_MODE';
    public const MODE_PSR3 = 'psr3';
    public const MODE_OTEL = 'otel';
    private const MODES = [
        self::MODE_PSR3,
        self::MODE_OTEL,
    ];
    public const DEFAULT_MODE = self::MODE_PSR3;
    private static string $mode;

    /** @var API\LoggerInterface[] */
    private array $loggers = [];
    private API\LoggerProviderInterface $loggerProvider;
    private ?FormatterInterface $formatterInterface;

    /**
     * @psalm-suppress InvalidArgument
     */
    public function __construct(API\LoggerProviderInterface $loggerProvider, $level, bool $bubble = true, ?FormatterInterface $formatterInterface = null)
    {
        parent::__construct($level, $bubble);
        $this->loggerProvider = $loggerProvider;
        $this->formatterInterface = $formatterInterface;
        self::$mode = self::getMode();
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
        $logRecord = (new API\LogRecord())
            ->setTimestamp((int) $record['datetime']->format('Uu') * 1000)
            ->setSeverityNumber(API\Severity::fromPsr3($record['level_name']))
            ->setSeverityText($record['level_name'])
            ->setBody($formatted['message'])
        ;
        foreach (['context', 'extra'] as $key) {
            if (self::$mode === self::MODE_PSR3 && isset($formatted[$key]) && count($formatted[$key]) > 0) {
                $logRecord->setAttribute($key, $formatted[$key]);
            }
            if (isset($record[$key]) && $record[$key] !== []) {
                foreach ($record[$key] as $attributeName => $attribute) {
                    if (
                        $key === 'context'
                        && $attributeName === 'exception'
                        && $attribute instanceof Throwable
                    ) {
                        $logRecord->setAttribute(ExceptionAttributes::EXCEPTION_TYPE, $attribute::class);
                        $logRecord->setAttribute(ExceptionAttributes::EXCEPTION_MESSAGE, $attribute->getMessage());
                        $logRecord->setAttribute(ExceptionAttributes::EXCEPTION_STACKTRACE, StackTraceFormatter::format($attribute));

                        continue;
                    }
                    switch (self::$mode) {
                        case self::MODE_PSR3:
                            $logRecord->setAttribute(sprintf('%s.%s', $key, $attributeName), $attribute);

                            break;
                        case self::MODE_OTEL:
                            $logRecord->setAttribute($attributeName, $attribute);

                            break;
                    }
                }
            }
        }
        $this->getLogger($record['channel'])->emit($logRecord);
    }

    private static function getMode(): string
    {
        $resolver = new ConfigurationResolver();
        if ($resolver->has(self::OTEL_PHP_MONOLOG_ATTRIB_MODE)) {
            $val = $resolver->getString(self::OTEL_PHP_MONOLOG_ATTRIB_MODE);
            if ($val && in_array($val, self::MODES)) {
                return $val;
            }
        }

        return self::DEFAULT_MODE;
    }
}

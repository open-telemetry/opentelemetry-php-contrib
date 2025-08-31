<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr3;

use JsonSerializable;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use Stringable;
use Throwable;

class Formatter
{

    use LogsMessagesTrait;

    public static function format(array $context): array
    {
        $formatted = [];
        foreach ($context as $key => $value) {
            if ($key === 'exception' && $value instanceof Throwable) {
                $formatted[$key] = self::formatThrowable($value);
            } else {
                switch (gettype($value)) {
                    case 'integer':
                    case 'double':
                    case 'boolean':
                        $formatted[$key] = $value;

                        break;
                    case 'string':
                    case 'array':
                        // Handle UTF-8 encoding issues
                        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
                        if ($encoded === false) {
                            self::logWarning('Failed to encode value: ' . json_last_error_msg());
                        } else {
                            $formatted[$key] = json_decode($encoded);
                        }

                        break;
                    case 'object':
                        if ($value instanceof Stringable) {
                            $formatted[$key] = (string) $value;
                        } elseif ($value instanceof JsonSerializable) {
                            $formatted[$key] = $value->jsonSerialize();
                        }

                        break;
                    default:
                        //do nothing
                }
            }
        }

        return $formatted;
    }

    private static function formatThrowable(?Throwable $exception): array
    {
        if ($exception) {
            return [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace(),
                'previous' => self::formatThrowable($exception->getPrevious()),
            ];
        }

        return [];
    }
}

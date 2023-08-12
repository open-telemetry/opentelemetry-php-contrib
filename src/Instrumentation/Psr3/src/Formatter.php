<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr3;

use Throwable;

class Formatter
{
    public static function format(array $context): array
    {
        $return = [];
        foreach ($context as $key => $value) {
            if ($key === 'exception' && $value instanceof Throwable) {
                $return[$key] = self::formatThrowable($value);
            } else {
                $return[$key] = json_decode(json_encode($value));
            }
        }

        return $return;
    }

    public static function formatThrowable(?Throwable $exception): array
    {
        if($exception) {
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

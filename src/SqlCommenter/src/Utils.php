<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\SqlCommenter;

class Utils
{
    public static function formatComments(array $comments): string
    {
        if (empty($comments)) {
            return '';
        }

        return '/*' . implode(
            ',',
            array_map(
                static fn (string $value, string $key) => urlencode($key) . "='" . urlencode($value) . "'",
                $comments,
                array_keys($comments)
            ),
        ) . '*/';
    }
}

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
                static fn (string $value, string $key) => Utils::customUrlEncode($key) . "='" . Utils::customUrlEncode($value) . "'",
                $comments,
                array_keys($comments)
            ),
        ) . '*/';
    }

    private static function customUrlEncode(string $input): string
    {
        $encodedString = urlencode($input);

        // Since SQL uses '%' as a keyword, '%' is a by-product of url quoting
        // e.g. foo,bar --> foo%2Cbar
        // thus in our quoting, we need to escape it too to finally give
        //      foo,bar --> foo%%2Cbar

        return str_replace('%', '%%', $encodedString);
    }
}

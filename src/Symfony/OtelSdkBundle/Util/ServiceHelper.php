<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\Util;

class ServiceHelper
{
    /**
     * @param string $class
     * @return string
     */
    public static function classToId(string $class): string
    {
        return str_replace(
            '\_',
            '.',
            strtolower(
                ltrim(
                    preg_replace(
                        '/[A-Z]([A-Z](?![a-z]))*/',
                        '_$0',
                        $class
                    ),
                    '_'
                )
            )
        );
    }

    /**
     * Converts a float into a string. Decimals > 12  are rounded.
     *
     * @param float $value
     * @return string
     */
    public static function floatToString(float $value): string
    {
        return number_format(
            $value,
            (int) strpos(
                strrev((string) $value),
                '.'
            ),
            '.',
            ''
        );
    }
}

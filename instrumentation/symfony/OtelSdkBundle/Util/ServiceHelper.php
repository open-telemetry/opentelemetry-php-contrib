<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Symfony\OtelSdkBundle\Util;

class ServiceHelper
{
    /**
     * @param string $class
     * @psalm-param class-string $class
     * @return string
     */
    public static function classToId(string $class): string
    {
        return str_replace(
            '\_',
            '.',
            ltrim(
                strtolower(
                    preg_replace(
                        '/[A-Z]([A-Z](?![a-z]))*/',
                        '_$0',
                        $class
                    )
                ),
                '_'
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
        $precision = (int) strpos(strrev((string) $value), '.');
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

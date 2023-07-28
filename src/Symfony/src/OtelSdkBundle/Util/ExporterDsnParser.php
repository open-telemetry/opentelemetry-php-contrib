<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Util;

use InvalidArgumentException;

class ExporterDsnParser
{
    public static function parse(string $dsn): ExporterDsn
    {
        return ExporterDsn::fromArray(self::parseToArray($dsn));
    }

    public static function parseToArray(string $dsn): array
    {
        $components = parse_url($dsn);
        if ($components === false) {
            throw new InvalidArgumentException('Could not parse DSN');
        }

        if (!isset($components['scheme']) || (int) strpos($components['scheme'], '+') === 0) {
            throw new InvalidArgumentException(
                'An exporter DSN must have a exporter type and a scheme: type+scheme://host:port'
            );
        }
        list($components['type'], $components['scheme']) = explode('+', $components['scheme']);

        $components['options'] = [];
        if (isset($components['query'])) {
            foreach (explode('&', $components['query']) as $part) {
                list($key, $value) = explode('=', $part);
                $components['options'][$key] = $value;
            }
        }

        unset($components['query']);
        if (isset($components['pass'])) {
            $components['password'] = $components['pass'];
            unset($components['pass']);
        }

        return $components;
    }
}

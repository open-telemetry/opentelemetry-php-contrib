<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Symfony\OpenTelemetryBundle\Util;

use InvalidArgumentException;
use Throwable;

class ExporterDsnParser
{
    public static function parse(string $dsn): ExporterDsn
    {
        return ExporterDsn::fromArray(self::parseToArray($dsn));
    }

    public static function parseToArray(string $dsn): array
    {
        $components = parse_url($dsn);
        if($components === false){
            throw new InvalidArgumentException('Could not parse DSN');
        }
        try {
            list($components['type'], $components['scheme']) = explode('+', $components['scheme']);
        } catch (Throwable $t){
            throw new InvalidArgumentException(
                'An exporter DSN must have a collector type and a scheme: type+scheme://host:port'
            );
        }
        $components['options'] = [];
        if(isset($components['query'])) {
            foreach (explode('&', $components['query']) as $part){
                list($key, $value) = explode('=', $part);
                $components['options'][$key] = $value;
            }
        }

        unset($components['query']);
        if(isset($components['pass'])){
            $components['password'] = $components['pass'];
            unset($components['pass']);
        }

        return $components;
    }
}
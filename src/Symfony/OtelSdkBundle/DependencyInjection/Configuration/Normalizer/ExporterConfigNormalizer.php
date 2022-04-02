<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Normalizer;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator\TypedExporterConfigValidator;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\Util\ExporterDsnParser;

class ExporterConfigNormalizer
{
    private const ENV_PREFIX = 'env_';

    public static function normalize($config): array
    {
        if (is_array($config)) {
            // exporter is set via - dsn: type+scheme://host:123/path
            if (isset($config[ConfigurationInterface::DSN_NODE])) {
                $dsn = $config[ConfigurationInterface::DSN_NODE];
                if (is_string($dsn)) {
                    return self::normalize($dsn);
                }

                throw new ConfigurationException(
                    'Exporter configuration "dsn" value must be a string'
                );
            }
            if (isset($config[ConfigurationInterface::TYPE_NODE])) {
                TypedExporterConfigValidator::approve($config);

                return $config;
            }

            throw new ConfigurationException(
                'Exporter configuration must either have a key "dsn", keys "type"+"url" or type=custom and keys "class" or "id". given: '
                . print_r($config, true)
            );
        }
        if (is_string($config)) {
            if (self::isEnvVarReference($config)) {
                return [
                    ConfigurationInterface::TYPE_NODE => ConfigurationInterface::ENV_TYPE,
                    ConfigurationInterface::URL_NODE => $config,
                ];
            }

            return self::exporterDsnToArray($config);
        }

        throw new ConfigurationException(
            'Exporter configuration must be either a dsn or an array'
        );
    }

    private static function exporterDsnToArray(string $config): array
    {
        return ExporterDsnParser::parse($config)->asConfigArray();
    }

    private static function isEnvVarReference(string $value): bool
    {
        return stripos($value, self::ENV_PREFIX) === 0;
    }
}

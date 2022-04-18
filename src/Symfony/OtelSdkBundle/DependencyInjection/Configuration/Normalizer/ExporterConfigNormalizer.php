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
            return self::normalizeArray($config);
        }

        if (is_string($config)) {
            return self::normalizeString($config);
        }

        throw new ConfigurationException(
            'Exporter configuration must be either a dsn or an array'
        );
    }

    private static function normalizeString(string $config): array
    {
        if (self::isEnvVarReference($config)) {
            return [
                ConfigurationInterface::TYPE_NODE => ConfigurationInterface::ENV_TYPE,
                ConfigurationInterface::URL_NODE => $config,
            ];
        }

        return self::exporterDsnToArray($config);
    }

    private static function normalizeArray(array $config): array
    {
        // exporter is set via - dsn: type+scheme://host:123/path
        if (isset($config[ConfigurationInterface::DSN_NODE])) {
            return self::normalizeDsnNode($config);
        }

        if (isset($config[ConfigurationInterface::TYPE_NODE])) {
            return self::normalizeTypedNode($config);
        }

        throw new ConfigurationException(
            'Exporter configuration must either have a key "dsn", keys "type"+"url" or type=custom and keys "class" or "id". given: '
            . print_r($config, true)
        );
    }

    private static function normalizeDsnNode(array $config): array
    {
        $dsn = $config[ConfigurationInterface::DSN_NODE];
        if (is_string($dsn)) {
            return self::normalize($dsn);
        }

        throw new ConfigurationException(
            'Exporter configuration "dsn" value must be a string'
        );
    }

    private static function normalizeTypedNode(array $config): array
    {
        TypedExporterConfigValidator::approve($config);

        return $config;
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

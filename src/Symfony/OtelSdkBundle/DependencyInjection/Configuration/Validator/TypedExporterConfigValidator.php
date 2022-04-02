<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;

class TypedExporterConfigValidator
{
    public static function approve(array $config): void
    {
        if (!isset($config[ConfigurationInterface::TYPE_NODE])) {
            throw new ConfigurationException('No node type set');
        }

        // custom exporter
        if ($config[ConfigurationInterface::TYPE_NODE] === ConfigurationInterface::CUSTOM_TYPE) {
            CustomExporterConfigValidator::approve($config);
        }
        // @todo add validation below
        // exporter is set via eg.  - [type: jaeger, url: scheme://host:123/path]
        /**
        if (isset($config[ConfigurationInterface::URL_NODE])) {
            //return $config;
        } */
    }
}

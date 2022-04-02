<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;

class CustomServiceValidator
{
    public static function approve(array $config, string $type = ''): void
    {
        if (!isset($config[ConfigurationInterface::CLASS_NODE]) && !isset($config[ConfigurationInterface::ID_NODE])) {
            throw new ConfigurationException(
                sprintf(
                    'Custom %s service needs a "class" or "id" option to be configured',
                    $type
                )
            );
        }
        if (isset($config[ConfigurationInterface::CLASS_NODE], $config[ConfigurationInterface::ID_NODE])) {
            throw new ConfigurationException(
                sprintf(
                    'Custom %s service needs either a "class" or "id" option to be configured, not both',
                    $type
                )
            );
        }
        if (isset($config[ConfigurationInterface::CLASS_NODE])) {
            CustomClassValidator::approve($config[ConfigurationInterface::CLASS_NODE]);
        }
    }
}

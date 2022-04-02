<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator;

use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;

class CustomExporterConfigValidator
{
    private const EXPORTER_HR = 'span exporter';

    public static function approve(array $config): void
    {
        // custom exporters need class or id provided.
        CustomServiceValidator::approve(
            $config,
            self::EXPORTER_HR
        );

        if (isset($config[ConfigurationInterface::CLASS_NODE])) {
            // custom exporters classes need to be a valid FQCN
            CustomClassValidator::approve(
                $config[ConfigurationInterface::CLASS_NODE],
                self::EXPORTER_HR
            );
            // custom span exporters need to implement OpenTelemetry\SDK\Trace\SpanExporterInterface
            CustomClassImplementationValidator::approve(
                $config[ConfigurationInterface::CLASS_NODE],
                SpanExporterInterface::class,
                self::EXPORTER_HR
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

use OpenTelemetry\SDK\Common\Configuration\Configuration;

class ContextPropagation
{
    public static function isEnabled(): bool
    {
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration') && Configuration::getBoolean('OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATION', false)) {
            return true;
        }

        return filter_var(get_cfg_var('otel.instrumentation.pdo.context_propagation'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    public static function isAttributeEnabled(): bool
    {
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration') && Configuration::getBoolean('OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATION_ATTRIBUTE', false)) {
            return true;
        }

        return filter_var(get_cfg_var('otel.instrumentation.pdo.context_propagation.attribute'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}

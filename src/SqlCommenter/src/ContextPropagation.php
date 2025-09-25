<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\SqlCommenter;

class ContextPropagation
{
    public static function isAttributeEnabled(): bool
    {
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration') && \OpenTelemetry\SDK\Common\Configuration\Configuration::getBoolean('OTEL_PHP_SQLCOMMENTER_ATTRIBUTE', false)) {
            return true;
        }

        return filter_var(get_cfg_var('otel.sqlcommenter.attribute'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig\ConfigEnv;

use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoader;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoaderRegistry;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvResolver;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpConfig;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\DefaultSanitizer;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\MultiSanitizer;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\RedactSensitiveQueryStringValuesSanitizer;

/**
 * @implements EnvComponentLoader<InstrumentationConfiguration>
 */
final class InstrumentationConfigurationHttpConfig implements EnvComponentLoader
{

    public function load(EnvResolver $env, EnvComponentLoaderRegistry $registry, Context $context): InstrumentationConfiguration
    {
        $sanitizers = [new DefaultSanitizer()];
        if ($sanitizeFieldNames = $env->list('OTEL_PHP_INSTRUMENTATION_URL_SANITIZE_FIELD_NAMES')) {
            $sanitizers[] = new RedactSensitiveQueryStringValuesSanitizer($sanitizeFieldNames);
        }

        return new HttpConfig(
            sanitizer: MultiSanitizer::composite($sanitizers),
            knownHttpMethods: $env->list('OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS') ?? HttpConfig::HTTP_METHODS,
        );
    }

    public function name(): string
    {
        return HttpConfig::class;
    }
}

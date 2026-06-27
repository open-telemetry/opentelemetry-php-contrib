<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig;

use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\DefaultSanitizer;

final class HttpConfig implements InstrumentationConfiguration
{

    /**
     * @see https://opentelemetry.io/docs/specs/semconv/registry/attributes/http/#http-request-method
     */
    public const HTTP_METHODS = ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'QUERY', 'TRACE'];

    public function __construct(
        public readonly HttpClientConfig $client = new HttpClientConfig(),
        public readonly HttpServerConfig $server = new HttpServerConfig(),
        public readonly UriSanitizer $sanitizer = new DefaultSanitizer(),
        public readonly array $knownHttpMethods = HttpConfig::HTTP_METHODS,
    ) {
    }
}

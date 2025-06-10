<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig;

use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\DefaultSanitizer;

final class HttpConfig implements InstrumentationConfiguration
{

    /**
     * @see https://www.rfc-editor.org/rfc/rfc9110.html#name-methods
     * @see https://www.rfc-editor.org/rfc/rfc5789.html
     */
    public const HTTP_METHODS = ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'TRACE'];

    public function __construct(
        public readonly HttpClientConfig $client = new HttpClientConfig(),
        public readonly HttpServerConfig $server = new HttpServerConfig(),
        public readonly UriSanitizer $sanitizer = new DefaultSanitizer(),
        public readonly array $knownHttpMethods = HttpConfig::HTTP_METHODS,
    ) {
    }
}

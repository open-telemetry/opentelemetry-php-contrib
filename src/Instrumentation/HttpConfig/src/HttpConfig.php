<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig;

use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\DefaultSanitizer;

final readonly class HttpConfig implements InstrumentationConfiguration
{

    /**
     * @see https://www.rfc-editor.org/rfc/rfc9110.html#name-methods
     * @see https://www.rfc-editor.org/rfc/rfc5789.html
     */
    public const HTTP_METHODS = ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'TRACE'];

    public function __construct(
        public HttpClientConfig $client = new HttpClientConfig(),
        public HttpServerConfig $server = new HttpServerConfig(),
        public UriSanitizer $sanitizer = new DefaultSanitizer(),
        public array $knownHttpMethods = HttpConfig::HTTP_METHODS,
    ) {
    }
}

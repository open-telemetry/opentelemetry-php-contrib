<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig;

final readonly class HttpClientConfig
{

    public function __construct(
        public bool $captureUrlScheme = false,
        public bool $captureUrlTemplate = false,
        public bool $captureUserAgentOriginal = false,
        public bool $captureUserAgentSyntheticType = false,
        public bool $captureNetworkTransport = false,
        public bool $captureRequestBodySize = false,
        public bool $captureRequestSize = false,
        public bool $captureResponseBodySize = false,
        public bool $captureResponseSize = false,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig;

final class HttpClientConfig
{

    public function __construct(
        public readonly bool $captureUrlScheme = false,
        public readonly bool $captureUrlTemplate = false,
        public readonly bool $captureUserAgentOriginal = false,
        public readonly bool $captureUserAgentSyntheticType = false,
        public readonly bool $captureNetworkTransport = false,
        public readonly bool $captureRequestBodySize = false,
        public readonly bool $captureRequestSize = false,
        public readonly bool $captureResponseBodySize = false,
        public readonly bool $captureResponseSize = false,
    ) {
    }
}

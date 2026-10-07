<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig;

final class HttpServerConfig
{

    public function __construct(
        public readonly bool $captureClientPort = false,
        public readonly bool $captureUserAgentSyntheticType = false,
        public readonly bool $captureNetworkTransport = false,
        public readonly bool $captureNetworkLocalAddress = false,
        public readonly bool $captureNetworkLocalPort = false,
        public readonly bool $captureRequestBodySize = false,
        public readonly bool $captureRequestSize = false,
        public readonly bool $captureResponseBodySize = false,
        public readonly bool $captureResponseSize = false,
    ) {
    }
}

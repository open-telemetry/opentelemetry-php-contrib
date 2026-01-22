<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig;

final readonly class HttpServerConfig
{

    public function __construct(
        public bool $captureClientPort = false,
        public bool $captureUserAgentSyntheticType = false,
        public bool $captureNetworkTransport = false,
        public bool $captureNetworkLocalAddress = false,
        public bool $captureNetworkLocalPort = false,
        public bool $captureRequestBodySize = false,
        public bool $captureRequestSize = false,
        public bool $captureResponseBodySize = false,
        public bool $captureResponseSize = false,
    ) {
    }
}

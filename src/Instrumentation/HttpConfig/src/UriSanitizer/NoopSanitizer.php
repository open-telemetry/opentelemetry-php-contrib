<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer;

use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer;
use Override;
use Psr\Http\Message\UriInterface;

final class NoopSanitizer implements UriSanitizer
{

    #[Override]
    public function sanitize(UriInterface $uri): UriInterface
    {
        return $uri;
    }
}

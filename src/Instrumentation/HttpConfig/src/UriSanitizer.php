<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig;

use Psr\Http\Message\UriInterface;

interface UriSanitizer
{

    public function sanitize(UriInterface $uri): UriInterface;
}

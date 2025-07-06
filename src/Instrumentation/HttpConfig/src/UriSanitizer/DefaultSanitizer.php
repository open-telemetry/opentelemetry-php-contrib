<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer;

use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer;
use Override;
use Psr\Http\Message\UriInterface;

/**
 * Applies default sanitization as specified by semantic conventions.
 *
 * @see https://opentelemetry.io/docs/specs/semconv/registry/attributes/url/#url-full
 */
final class DefaultSanitizer implements UriSanitizer
{

    private readonly UriSanitizer $sanitizer;

    public function __construct()
    {
        $this->sanitizer = MultiSanitizer::composite([
            new RedactUsernamePasswordSanitizer(),
            new RedactSensitiveQueryStringValuesSanitizer(['AWSAccessKeyId', 'Signature', 'sig', 'X-Goog-Signature']),
        ]);
    }

    #[Override]
    public function sanitize(UriInterface $uri): UriInterface
    {
        return $this->sanitizer->sanitize($uri);
    }
}

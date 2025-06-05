<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer;

use function array_key_first;
use function count;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer;
use Psr\Http\Message\UriInterface;

final class MultiSanitizer implements UriSanitizer
{

    /**
     * @param iterable<UriSanitizer> $sanitizers
     */
    private function __construct(
        private readonly iterable $sanitizers,
    ) {
    }

    /**
     * @param array<UriSanitizer> $sanitizers
     */
    public static function composite(array $sanitizers): UriSanitizer
    {
        return match (count($sanitizers)) {
            0 => new NoopSanitizer(),
            1 => $sanitizers[array_key_first($sanitizers)],
            default => new self($sanitizers),
        };
    }

    public function sanitize(UriInterface $uri): UriInterface
    {
        foreach ($this->sanitizers as $sanitizer) {
            $uri = $sanitizer->sanitize($uri);
        }

        return $uri;
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer;

use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer;
use Override;
use Psr\Http\Message\UriInterface;
use function strlen;
use function strpos;
use function substr;
use function substr_compare;

final class RedactSensitiveQueryStringValuesSanitizer implements UriSanitizer
{

    /**
     * @param list<string> $redactedParameters sensitive query parameters to redact
     */
    public function __construct(
        private readonly array $redactedParameters,
    ) {
    }

    #[Override]
    public function sanitize(UriInterface $uri): UriInterface
    {
        $query = $uri->getQuery();
        $offset = 0;
        $sanitized = '';
        for ($i = 0, $n = strlen($query); $i < $n; $i = $d + 1) {
            if (($d = strpos($query, '&', $i)) === false) {
                $d = strlen($query);
            }

            foreach ($this->redactedParameters as $parameter) {
                $l = strlen($parameter);
                if (($query[$i + $l] ?? '') === '=' && !substr_compare($query, $parameter, $i, $l)) {
                    $sanitized .= substr($query, $offset, $i + $l + 1 - $offset);
                    $sanitized .= 'REDACTED';
                    $offset = $d;

                    break;
                }
            }
        }

        if ($offset === 0) {
            return $uri;
        }

        $sanitized .= substr($query, $offset);

        return $uri->withQuery($sanitized);
    }
}

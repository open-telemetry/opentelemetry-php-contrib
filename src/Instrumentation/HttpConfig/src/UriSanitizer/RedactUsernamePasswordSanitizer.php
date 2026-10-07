<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer;

use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer;
use Override;
use Psr\Http\Message\UriInterface;
use function str_contains;

final class RedactUsernamePasswordSanitizer implements UriSanitizer
{

    #[Override]
    public function sanitize(UriInterface $uri): UriInterface
    {
        $userInfo = $uri->getUserInfo();
        if ($userInfo === '') {
            return $uri;
        }

        return str_contains($userInfo, ':')
            ? $uri->withUserInfo('REDACTED', 'REDACTED')
            : $uri->withUserInfo('REDACTED');
    }
}

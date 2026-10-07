<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\HttpConfig\Unit;

use League\Uri\Http;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\RedactSensitiveQueryStringValuesSanitizer;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\RedactUsernamePasswordSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;

final class UriSanitizerTest extends TestCase
{

    #[DataProvider('redactUsernamePasswordProvider')]
    public function testRedactUsernamePassword(UriInterface $uri, UriInterface $expected): void
    {
        $sanitizer = new RedactUsernamePasswordSanitizer();
        $this->assertEquals($expected, $sanitizer->sanitize($uri));
    }

    public static function redactUsernamePasswordProvider(): iterable
    {
        yield 'no userinfo' => [
            Http::new('https://example.com'),
            Http::new('https://example.com'),
        ];
        yield 'username only' => [
            Http::new('https://user@example.com'),
            Http::new('https://REDACTED@example.com'),
        ];
        yield 'username+password' => [
            Http::new('https://user:pass@example.com'),
            Http::new('https://REDACTED:REDACTED@example.com'),
        ];
    }

    #[DataProvider('redactSensitiveQueryStringValuesProvider')]
    public function testRedactSensitiveQueryStringValues(UriInterface $uri, UriInterface $expected): void
    {
        $sanitizer = new RedactSensitiveQueryStringValuesSanitizer(['secret', 'pass', 'passwd']);
        $this->assertEquals($expected, $sanitizer->sanitize($uri));
    }

    public static function redactSensitiveQueryStringValuesProvider(): iterable
    {
        yield 'no query string' => [
            Http::new('https://example.com'),
            Http::new('https://example.com'),
        ];
        yield 'non-sensitive query string' => [
            Http::new('https://example.com?key=value'),
            Http::new('https://example.com?key=value'),
        ];
        yield 'sensitive query string' => [
            Http::new('https://example.com?secret=value'),
            Http::new('https://example.com?secret=REDACTED'),
        ];
        yield 'multiple sensitive query strings' => [
            Http::new('https://example.com?secret=value&pass=test'),
            Http::new('https://example.com?secret=REDACTED&pass=REDACTED'),
        ];
        yield 'multiple sensitive query strings with values longer than "REDACTED"' => [
            Http::new('https://example.com?secret=value0123456789&pass=test0123456789'),
            Http::new('https://example.com?secret=REDACTED&pass=REDACTED'),
        ];
        yield 'mixed sensitive and non-sensitive query string' => [
            Http::new('https://example.com?secret=value&key=value&pass=1234&timestamp=123456789'),
            Http::new('https://example.com?secret=REDACTED&key=value&pass=REDACTED&timestamp=123456789'),
        ];
    }
}

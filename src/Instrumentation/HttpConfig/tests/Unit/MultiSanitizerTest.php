<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\HttpConfig\Unit;

use League\Uri\Http;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\MultiSanitizer;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\NoopSanitizer;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\RedactSensitiveQueryStringValuesSanitizer;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\RedactUsernamePasswordSanitizer;
use PHPUnit\Framework\TestCase;

final class MultiSanitizerTest extends TestCase
{
    public function testCompositeWithNoSanitizersReturnsNoop(): void
    {
        $sanitizer = MultiSanitizer::composite([]);
        $this->assertInstanceOf(NoopSanitizer::class, $sanitizer);
    }

    public function testCompositeWithOneSanitizerReturnsThatSanitizer(): void
    {
        $inner = new RedactUsernamePasswordSanitizer();
        $sanitizer = MultiSanitizer::composite([$inner]);
        $this->assertSame($inner, $sanitizer);
    }

    public function testCompositeWithMultipleSanitizersReturnsMulti(): void
    {
        $sanitizer = MultiSanitizer::composite([
            new RedactUsernamePasswordSanitizer(),
            new RedactSensitiveQueryStringValuesSanitizer(['secret']),
        ]);
        $this->assertInstanceOf(MultiSanitizer::class, $sanitizer);
    }

    public function testSanitizeAppliesAllSanitizers(): void
    {
        $sanitizer = MultiSanitizer::composite([
            new RedactUsernamePasswordSanitizer(),
            new RedactSensitiveQueryStringValuesSanitizer(['secret']),
        ]);

        $uri = Http::new('https://user:pass@example.com?secret=value&key=safe');
        $result = $sanitizer->sanitize($uri);

        $this->assertSame('REDACTED:REDACTED', $result->getUserInfo());
        $this->assertSame('secret=REDACTED&key=safe', $result->getQuery());
    }

    public function testNoopSanitizer(): void
    {
        $sanitizer = new NoopSanitizer();
        $uri = Http::new('https://user:pass@example.com?secret=value');
        $result = $sanitizer->sanitize($uri);
        $this->assertSame($uri, $result);
    }
}

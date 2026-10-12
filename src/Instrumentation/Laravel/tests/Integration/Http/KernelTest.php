<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Http;

use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;

/** @psalm-suppress UnusedClass */
class KernelTest extends TestCase
{
    public function test_query_string_redaction(): void
    {
        $this->app['router']->get('/redact', fn () => null);

        $this->call('GET', '/redact?secret=password&plain=sight');

        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        self::assertSame('GET /redact', $span->getName());
        self::assertSame('http://localhost/redact?plain=sight&secret=REDACTED', $span->getAttributes()->get(UrlAttributes::URL_FULL));
        self::assertSame('plain=sight&secret=REDACTED', $span->getAttributes()->get(UrlAttributes::URL_QUERY));
    }
}

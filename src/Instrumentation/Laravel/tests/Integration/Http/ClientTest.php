<?php

declare(strict_types=1);

namespace Integration\Http;

use Illuminate\Support\Facades\Http;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;

class ClientTest extends TestCase
{
    /** @test */
    public function it_records_requests(): void
    {
        Http::fake([
            'ok.opentelemetry.io' => Http::response(status: 201),
            'missing.opentelemetry.io' => Http::response(status: 404),
        ]);

        $response = Http::get('missing.opentelemetry.io');
        $span = $this->storage[0];
        self::assertEquals(404, $response->status());
        self::assertEquals('HTTP GET', $span->getName());
        self::assertEquals('missing.opentelemetry.io', $span->getAttributes()->get(TraceAttributes::HTTP_TARGET));

        $response = Http::post('ok.opentelemetry.io');
        $span = $this->storage[1];
        self::assertEquals(201, $response->status());
        self::assertEquals('HTTP POST', $span->getName());
        self::assertEquals('ok.opentelemetry.io', $span->getAttributes()->get(TraceAttributes::HTTP_TARGET));
    }
}

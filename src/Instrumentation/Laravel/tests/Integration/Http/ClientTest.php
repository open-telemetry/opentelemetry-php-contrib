<?php

declare(strict_types=1);

namespace Integration\Http;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\RejectedPromise;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\StatusData;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;

/** @psalm-suppress UnusedClass */
class ClientTest extends TestCase
{
    public function test_it_records_requests(): void
    {
        Http::fake([
            'ok.opentelemetry.io/*' => Http::response(status: 201),
            'missing.opentelemetry.io' => Http::response(status: 404),
            'redirect.opentelemetry.io' => Http::response(status: 302),
        ]);

        $response = Http::get('missing.opentelemetry.io');
        $span = $this->storage[0];
        self::assertEquals(404, $response->status());
        self::assertEquals('GET', $span->getName());
        self::assertEquals('missing.opentelemetry.io', $span->getAttributes()->get(TraceAttributes::URL_PATH));
        self::assertEquals(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());

        $response = Http::post('ok.opentelemetry.io/foo?param=bar');
        $span = $this->storage[1];
        self::assertEquals(201, $response->status());
        self::assertEquals('POST', $span->getName());
        self::assertEquals('ok.opentelemetry.io/foo', $span->getAttributes()->get(TraceAttributes::URL_PATH));
        self::assertEquals(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());

        $response = Http::get('redirect.opentelemetry.io');
        $span = $this->storage[2];
        self::assertEquals(302, $response->status());
        self::assertEquals('GET', $span->getName());
        self::assertEquals('redirect.opentelemetry.io', $span->getAttributes()->get(TraceAttributes::URL_PATH));
        self::assertEquals(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    public function test_it_records_connection_failures(): void
    {
        Http::fake(fn (Request $request) => new RejectedPromise(new ConnectException('Failure', $request->toPsrRequest())));

        try {
            Http::patch('/fail');
        } catch (\Exception) {
        }

        $span = $this->storage[0];
        self::assertEquals('PATCH', $span->getName());
        self::assertEquals('http://fail', $span->getAttributes()->get(TraceAttributes::URL_FULL));
        self::assertEquals(StatusData::create(StatusCode::STATUS_ERROR, 'Connection failed'), $span->getStatus());
    }
}

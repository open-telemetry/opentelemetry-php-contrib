<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Exporter\Instana\Unit;

use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;
use OpenTelemetry\API\Trace\StatusCode;

use OpenTelemetry\Contrib\Exporter\Instana\SpanConverter;
use OpenTelemetry\Contrib\Exporter\Instana\SpanKind;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\StatusData;
use OpenTelemetry\Contrib\Exporter\Instana\Unit\SpanDataUtil as SpanDataUtil;

use PHPUnit\Framework\TestCase;

class SpanConverterTest extends TestCase
{
    private SpanConverter $converter;

    #[\Override]
    public function setUp(): void
    {
        $this->converter = new SpanConverter('0123456abcdef', '12345');
    }

    public function test_should_convert_a_span_to_a_payload_for_instana(): void
    {
        $span = (new SpanDataUtil())
            ->setName('converter.test')
            ->setKind(OtelSpanKind::KIND_CLIENT)
            ->setContext(
                SpanContext::create(
                    'abcdef0123456789abcdef0123456789',
                    'aabbccddeeff0123'
                )
            )
            ->setParentContext(
                SpanContext::create(
                    '10000000000000000000000000000000',
                    '1000000000000000'
                )
            )
            ->setStatus(
                new StatusData(
                    StatusCode::STATUS_ERROR,
                    'status_description'
                )
            )
            ->setInstrumentationScope(new InstrumentationScope(
                'instrumentation_scope_name',
                'instrumentation_scope_version',
                null,
                Attributes::create([]),
            ))
            ->addAttribute('service', ['name' => 'unknown_service:php', 'version' => 'dev-main'])
            ->addAttribute('net.peer.name', 'authorizationservice.com')
            ->addAttribute('peer.service', 'AuthService')
            ->setResource(
                ResourceInfo::create(
                    Attributes::create([
                        'telemetry.sdk.name' => 'opentelemetry',
                        'telemetry.sdk.language' => 'php',
                        'telemetry.sdk.version' => 'dev',
                        'instance' => 'test-a',
                    ])
                )
            )
            ->addEvent('validators.list', Attributes::create(['job' => 'stage.updateTime']), 1505855799433901068)
            ->setHasEnded(true);

        $instanaSpan = $this->converter->convert([$span])[0];
        $this->assertSame('sdk', $instanaSpan['n']);
        $this->assertSame($span->getContext()->getTraceId(), $instanaSpan['t']);
        $this->assertSame($span->getContext()->getSpanId(), $instanaSpan['s']);
        $this->assertSame(1505855794194, $instanaSpan['ts']);
        $this->assertSame(5271, $instanaSpan['d']);
        $this->assertSame('12345', $instanaSpan['f']['e']);
        $this->assertSame('0123456abcdef', $instanaSpan['f']['h']);
        $this->assertSame('1000000000000000', $instanaSpan['p']);
        $this->assertSame(2, $instanaSpan['k']);

        $this->assertCount(2, $instanaSpan['data']);
        $this->assertSame('instana/opentelemetry-php-exporter', $instanaSpan['data']['service']);
        $this->assertSame($span->getName(), $instanaSpan['data']['sdk']['name']);

        $tags = $instanaSpan['data']['sdk']['custom']['tags'];
        $this->assertCount(7, $tags);
        $this->assertSame('opentelemetry', $tags['telemetry.sdk.name']);
        $this->assertSame('php', $tags['telemetry.sdk.language']);
        $this->assertSame('dev', $tags['telemetry.sdk.version']);
        $this->assertSame('test-a', $tags['instance']);

        $this->assertCount(3, $tags['attributes']);
        $this->assertSame('unknown_service:php', $tags['attributes']['service']['name']);
        $this->assertSame('dev-main', $tags['attributes']['service']['version']);
        $this->assertSame('authorizationservice.com', $tags['attributes']['net.peer.name']);
        $this->assertSame('AuthService', $tags['attributes']['peer.service']);

        $this->assertSame('{"value":{"job":"stage.updateTime"},"timestamp":1505855799433}', $tags['events']['validators.list']);

        $this->assertCount(4, $tags['otel']);
        $this->assertSame('instrumentation_scope_name', $tags['otel']['scope.name']);
        $this->assertSame('instrumentation_scope_version', $tags['otel']['scope.version']);
        $this->assertSame('Error', $tags['otel']['status_code']);
        $this->assertSame('status_description', $tags['otel']['error']);
    }

    /**
    * @dataProvider spanConverterProvider
    */
    public function test_should_throw_on_missing_construction(SpanConverter $converter): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get agentUuid or agentPid');
        $span = (new SpanDataUtil());
        $converter->convert([$span]);
    }

    public static function spanConverterProvider(): array
    {
        return [
            'default' => [new SpanConverter()],
            'wo_uuid' => [new SpanConverter(agentPid: '12345')],
            'wo_pid' => [new SpanConverter(agentUuid: '0123456abcdef')],
        ];
    }

    public function test_should_omit_empty_keys_from_instana_span(): void
    {
        $span = (new SpanDataUtil());
        $instanaSpan = $this->converter->convert([$span])[0];

        $this->assertArrayNotHasKey('p', $instanaSpan);
        $this->assertSame('php', $instanaSpan['n']);
        $this->assertSame('instana/opentelemetry-php-exporter', $instanaSpan['data']['service']);
        $this->assertSame('test-span-data', $instanaSpan['data']['sdk']['name']);
        $this->assertCount(2, $instanaSpan['data']);
    }

    /**
    * @dataProvider spanKindProvider
    */
    public function test_should_convert_otel_span_to_an_instana_span(int $internalSpanKind, ?int $expectedSpanKind): void
    {
        $span = (new SpanDataUtil())
            ->setKind($internalSpanKind);

        $instanaSpan = $this->converter->convert([$span])[0];

        if ($internalSpanKind < 5) {
            $this->assertSame($expectedSpanKind, $instanaSpan['k']);
        } else {
            $this->assertArrayNotHasKey('k', $instanaSpan);
        }
    }

    public static function spanKindProvider(): array
    {
        return [
            'server' => [OtelSpanKind::KIND_SERVER, SpanKind::ENTRY],
            'client' => [OtelSpanKind::KIND_CLIENT, SpanKind::EXIT],
            'producer' => [OtelSpanKind::KIND_PRODUCER, SpanKind::EXIT],
            'consumer' => [OtelSpanKind::KIND_CONSUMER, SpanKind::ENTRY],
            'consumer_internal' => [OtelSpanKind::KIND_INTERNAL, SpanKind::INTERMEDIATE],
            'default' => [12345, null], // Some unsupported "enum"
        ];
    }

    public function test_should_convert_an_event_without_attributes_to_an_empty_event(): void
    {
        $span = (new SpanDataUtil())
            ->addEvent('event.name', Attributes::create([]));

        $instanaSpan = $this->converter->convert([$span])[0];

        $this->assertSame('{}', $instanaSpan['data']['sdk']['custom']['tags']['events']['event.name']);
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod,PossiblyInvalidArrayAccess
     */
    public function test_data_are_coerced_correctly_to_strings(): void
    {
        $listOfStrings = ['string-1', 'string-2'];
        $listOfNumbers = [1, 2, 3, 3.1415, 42];
        $listOfBooleans = [true, true, false, true];

        $span = (new SpanDataUtil())
            ->addAttribute('string', 'string')
            ->addAttribute('integer-1', 1024)
            ->addAttribute('integer-2', 0)
            ->addAttribute('float', 1.2345)
            ->addAttribute('boolean-1', true)
            ->addAttribute('boolean-2', false)
            ->addAttribute('list-of-strings', $listOfStrings)
            ->addAttribute('list-of-numbers', $listOfNumbers)
            ->addAttribute('list-of-booleans', $listOfBooleans);

        $data = $this->converter->convert([$span])[0]['data']['sdk']['custom']['tags']['attributes'];

        // Check that we captured all attributes in data.
        $this->assertCount(9, $data);

        $this->assertSame('string', $data['string']);
        $this->assertSame(1024, $data['integer-1']);
        $this->assertSame(0, $data['integer-2']);
        $this->assertSame(1.2345, $data['float']);
        $this->assertTrue($data['boolean-1']);
        $this->assertFalse($data['boolean-2']);

        // Lists are recovered and are the same.
        $this->assertSame($listOfStrings, $data['list-of-strings']);
        $this->assertSame($listOfNumbers, $data['list-of-numbers']);
        $this->assertSame($listOfBooleans, $data['list-of-booleans']);
    }

    /**
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/v1.20.0/specification/common/mapping-to-non-otlp.md#dropped-attributes-count
     */
    /**
    * @dataProvider droppedProvider
    */
    public function test_displays_non_zero_dropped_counts(int $dropped, bool $expected): void
    {
        $attributes = $this->createMock(AttributesInterface::class);
        $attributes->method('getDroppedAttributesCount')->willReturn($dropped);
        $spanData = $this->createMock(SpanDataInterface::class);
        $spanData->method('getAttributes')->willReturn($attributes);
        $spanData->method('getLinks')->willReturn([]);
        $spanData->method('getEvents')->willReturn([]);
        $spanData->method('getTotalDroppedEvents')->willReturn($dropped);
        $spanData->method('getTotalDroppedLinks')->willReturn($dropped);

        $converted = $this->converter->convert([$spanData])[0];
        $data = $converted['data']['sdk']['custom']['tags']['otel'];

        if ($expected) {
            $this->assertArrayHasKey(SpanConverter::OTEL_KEY_DROPPED_EVENTS_COUNT, $data);
            $this->assertSame($dropped, $data[SpanConverter::OTEL_KEY_DROPPED_EVENTS_COUNT]);
            $this->assertArrayHasKey(SpanConverter::OTEL_KEY_DROPPED_LINKS_COUNT, $data);
            $this->assertSame($dropped, $data[SpanConverter::OTEL_KEY_DROPPED_LINKS_COUNT]);
            $this->assertArrayHasKey(SpanConverter::OTEL_KEY_DROPPED_ATTRIBUTES_COUNT, $data);
            $this->assertSame($dropped, $data[SpanConverter::OTEL_KEY_DROPPED_ATTRIBUTES_COUNT]);
        } else {
            $this->assertArrayNotHasKey(SpanConverter::OTEL_KEY_DROPPED_EVENTS_COUNT, $data);
            $this->assertArrayNotHasKey(SpanConverter::OTEL_KEY_DROPPED_LINKS_COUNT, $data);
            $this->assertArrayNotHasKey(SpanConverter::OTEL_KEY_DROPPED_ATTRIBUTES_COUNT, $data);
        }
    }

    public static function droppedProvider(): array
    {
        return [
            'no dropped' => [0, false],
            'some dropped' => [1, true],
        ];
    }

    public function test_events(): void
    {
        $eventAttributes = $this->createMock(AttributesInterface::class);
        $eventAttributes->method('getDroppedAttributesCount')->willReturn(99);
        $attributes = [
            'a_one' => 123,
            'a_two' => 3.14159,
            'a_three' => true,
            'a_four' => false,
        ];
        $eventAttributes->method('count')->willReturn(count($attributes));
        $eventAttributes->method('toArray')->willReturn($attributes);
        $span = (new SpanDataUtil())
            ->setName('events.test')
            ->addEvent('event.one', $eventAttributes);
        $instanaSpan = $this->converter->convert([$span])[0];

        $events = $instanaSpan['data']['sdk']['custom']['tags']['events'];

        $this->assertTrue(array_key_exists('event.one', $events));
        $this->assertIsString($events['event.one']);
    }

    public function test_http_header_attributes(): void
    {
        putenv('OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS=secrets');
        putenv('OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS=agent');
        
        $span = (new SpanDataUtil())
            ->setName('converter.http')
            ->setKind(OtelSpanKind::KIND_CLIENT)
            ->addAttribute('http.request.method', 'GET')
            ->addAttribute('http.request.header', ['secret' => 'foo'])
            ->addAttribute('http.request.header.secrets', ['foo', 'bar'])
            ->addAttribute('http.response.status_code', 200)
            ->addAttribute('http.response.header.secrets', ['fizz', 'buzz'])
            ->addAttribute('http.response.header.agent', 'instana')
            ->addAttribute('http.request.header.agent', 'instana');

        $data = $this->converter->convert([$span])[0]['data']['sdk']['custom']['tags'];

        $this->assertArrayHasKey('http.request.method', $data['attributes']);
        $this->assertArrayHasKey('http.response.status_code', $data['attributes']);

        $this->assertArrayHasKey('http.request.header.agent', $data['attributes']);
        $this->assertArrayHasKey('http.response.header.secrets', $data['attributes']);
        $this->assertArrayNotHasKey('http.request.header.secrets', $data['attributes']);
        $this->assertArrayNotHasKey('http.response.header.agent', $data['attributes']);
        
    }
}

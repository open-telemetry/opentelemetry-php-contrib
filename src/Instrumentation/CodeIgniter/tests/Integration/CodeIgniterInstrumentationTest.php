<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\CodeIgniter\tests\Integration;

use CodeIgniter\Test\FeatureTestTrait;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;

class CodeIgniterInstrumentationTest extends AbstractTest
{
    use FeatureTestTrait;

    public function test_success()
    {
        // If no application directory is set, CodeIgniter defaults to a built-in demo application
        // that is bundled with the framework and includes a Home controller with index method.
        $routes = [
            ['get', 'home', 'Home::index'],
        ];

        $result = $this->withRoutes($routes)->get('home');
        /** @psalm-suppress InternalMethod */
        $result->assertStatus(200);

        $attributes = $this->storage[0]->getAttributes();
        $this->assertCount(1, $this->storage);
        $this->assertEqualsIgnoringCase('GET Home.index', $this->storage[0]->getName());
        $this->assertStringMatchesFormat('http://%s/home', $attributes->get(UrlAttributes::URL_FULL));
        $this->assertEqualsIgnoringCase('GET', $attributes->get(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertEquals('http', $attributes->get(UrlAttributes::URL_SCHEME));
        $this->assertEquals('Home.index', $attributes->get(HttpAttributes::HTTP_ROUTE));
        $this->assertEquals(200, $attributes->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertEquals('1.1', $attributes->get(NetworkAttributes::NETWORK_PROTOCOL_VERSION));
        $this->assertGreaterThan(0, $attributes->get(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE));
    }

    public function test_exception()
    {
        $routes = [
            ['get', 'exception', function (): string {
                throw new \Exception('Threw');
            }],
        ];

        $exceptionMessage = null;

        try {
            $this->withRoutes($routes)->get('exception');
        } catch (\Exception $e) {
            $exceptionMessage = $e->getMessage();
        }

        $this->assertEquals('Threw', $exceptionMessage);

        $attributes = $this->storage[0]->getAttributes();
        $this->assertCount(1, $this->storage);
        $this->assertEqualsIgnoringCase('GET Closure.index', $this->storage[0]->getName());
        $this->assertStringMatchesFormat('http://%s/exception', $attributes->get(UrlAttributes::URL_FULL));
        $this->assertEqualsIgnoringCase('GET', $attributes->get(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertEquals('http', $attributes->get(UrlAttributes::URL_SCHEME));
        $this->assertEquals('Closure.index', $attributes->get(HttpAttributes::HTTP_ROUTE));
        $this->assertNull($attributes->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertNull($attributes->get(NetworkAttributes::NETWORK_PROTOCOL_VERSION));
        $this->assertNull($attributes->get(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE));

        $status = $this->storage[0]->getStatus();
        $this->assertEquals('Error', $status->getCode());
        $this->assertEquals('Threw', $status->getDescription());

        $events = $this->storage[0]->getEvents();
        $this->assertCount(1, $events);
        $this->assertEquals('exception', $events[0]->getName());

        $eventAttributes = $events[0]->getAttributes();
        $this->assertEquals('Exception', $eventAttributes->get('exception.type'));
        $this->assertEquals('Threw', $eventAttributes->get('exception.message'));
        $this->assertNotNull($eventAttributes->get('exception.stacktrace'));
    }
}

<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\CodeIgniter\tests\Integration;

use CodeIgniter\Test\FeatureTestTrait;
use OpenTelemetry\SemConv\TraceAttributes;

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
        $this->assertEqualsIgnoringCase('GET', $this->storage[0]->getName());
        $this->assertStringMatchesFormat('http://%s/home', $attributes->get(TraceAttributes::URL_FULL));
        $this->assertEqualsIgnoringCase('GET', $attributes->get(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertEquals('http', $attributes->get(TraceAttributes::URL_SCHEME));
        $this->assertEquals('Home.index', $attributes->get(TraceAttributes::HTTP_ROUTE));
        $this->assertEquals(200, $attributes->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertEquals('1.1', $attributes->get(TraceAttributes::NETWORK_PROTOCOL_VERSION));
        $this->assertGreaterThan(0, $attributes->get(TraceAttributes::HTTP_RESPONSE_BODY_SIZE));
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
        $this->assertEqualsIgnoringCase('GET', $this->storage[0]->getName());
        $this->assertStringMatchesFormat('http://%s/exception', $attributes->get(TraceAttributes::URL_FULL));
        $this->assertEqualsIgnoringCase('GET', $attributes->get(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertEquals('http', $attributes->get(TraceAttributes::URL_SCHEME));
        $this->assertEquals('Closure.index', $attributes->get(TraceAttributes::HTTP_ROUTE));
        $this->assertNull($attributes->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertNull($attributes->get(TraceAttributes::NETWORK_PROTOCOL_VERSION));
        $this->assertNull($attributes->get(TraceAttributes::HTTP_RESPONSE_BODY_SIZE));

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
        $this->assertTrue($eventAttributes->get('exception.escaped'));
    }
}

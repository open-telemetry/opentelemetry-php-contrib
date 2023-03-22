<?php

namespace OpenTelemetry\Tests\Instrumentation\Laravel\tests\Integration;

use OpenTelemetry\Tests\Instrumentation\Laravel\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}

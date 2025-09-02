<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PhpSession\tests\Integration;

use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Contrib\Instrumentation\PhpSession\PhpSessionInstrumentation;
use OpenTelemetry\SemConv\TraceAttributes;

class PhpSessionInstrumentationTest extends AbstractTest
{
    public function setUp(): void
    {
        parent::setUp();
        
        // Register the instrumentation
        PhpSessionInstrumentation::register();
        
        // Make sure no session is active at the start of each test
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
    
    public function tearDown(): void
    {
        // Clean up any active sessions
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        
        parent::tearDown();
    }
    
    /**
     * @runInSeparateProcess
     */
    public function test_session_start(): void
    {
        // Start a session with some options
        $options = [
            'read_and_close' => true,
            'cookie_lifetime' => 3600,
        ];
    
        $return = session_start($options);
        
        // Verify the span was created
        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];

        // Check span name
        $this->assertEquals('session.start', $span->getName());
        
        // Check attributes
        $attributes = $span->getAttributes();

        $this->assertEquals('session_start', $attributes->get(TraceAttributes::CODE_FUNCTION_NAME));
        
        // Check session options were recorded
        $this->assertTrue($attributes->get('php.session.options.read_and_close'));
        $this->assertEquals(3600, $attributes->get('php.session.options.cookie_lifetime'));
        
        // Check session information
        $this->assertEquals(session_id(), $attributes->get('php.session.id'));
        $this->assertEquals(session_name(), $attributes->get('php.session.name'));
        $this->assertEquals($return, $attributes->get('php.session.status'));
        
        // Check cookie parameters
        $cookieParams = session_get_cookie_params();
        $expectedKeys = [];
        ksort($cookieParams);
        foreach ($cookieParams as $key => $value) {
            if (is_scalar($value)) {
                $expectedKeys[] = $key;
            }
        }
        $actualKeys = $attributes->get('php.session.cookie.keys');
        $this->assertEquals($expectedKeys, $actualKeys);

        // Check status
        $this->assertEquals(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        
        // Clean up
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
    
    /**
     * @runInSeparateProcess
     */
    public function test_session_destroy(): void
    {
        // Start a session first
        session_start();

        // Destroy the session
        session_destroy();
        
        // Verify the span were created for session_start() and session_destroy()
        $this->assertCount(2, $this->storage);
        $span = $this->storage[1];
        
        // Check span name
        $this->assertEquals('session.destroy', $span->getName());
        
        // Check attributes
        $attributes = $span->getAttributes();

        $this->assertEquals('session_destroy', $attributes->get(TraceAttributes::CODE_FUNCTION_NAME));

        // Check session information
        $this->assertNotNull($attributes->get('php.session.id'));
        $this->assertNotNull($attributes->get('php.session.name'));
        
        // Check status
        $this->assertEquals(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }
    /**
     * @runInSeparateProcess
     */
    public function test_session_write_close(): void
    {
        // Start a session first
        session_start();
        
        // Set a session variable
        $_SESSION['test'] = 'value';
                
        // Write and close the session
        session_write_close();
        
        // Verify the span was created
        $this->assertCount(2, $this->storage);
        $span = $this->storage[1];
        
        // Check span name
        $this->assertEquals('session.write_close', $span->getName());
        
        // Check attributes
        $attributes = $span->getAttributes();
        $this->assertEquals('session_write_close', $attributes->get(TraceAttributes::CODE_FUNCTION_NAME));

        // Check session information
        $this->assertEquals(session_id(), $attributes->get('php.session.id'));
        $this->assertNotNull($attributes->get('php.session.name'));
        
        // Check status
        $this->assertEquals(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }
    
    /**
     * @runInSeparateProcess
     */
    public function test_session_unset(): void
    {
        // Start a session first
        session_start();
        
        // Set a session variable
        $_SESSION['test'] = 'value';
        
        // Clear the storage to only capture the unset operation
        $this->storage->exchangeArray([]);
        
        // Unset all session variables
        session_unset();
        
        // Verify the span was created
        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        
        // Check span name
        $this->assertEquals('session.unset', $span->getName());
        
        // Check attributes
        $attributes = $span->getAttributes();
        $this->assertEquals('session_unset', $attributes->get(TraceAttributes::CODE_FUNCTION_NAME));
        
        // Check session information
        $this->assertNotNull($attributes->get('php.session.id'));
        $this->assertNotNull($attributes->get('php.session.name'));
        
        // Check status
        $this->assertEquals(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        
        // Clean up
        session_destroy();
    }
    
    /**
     * @runInSeparateProcess
     */
    public function test_session_abort(): void
    {
        // Start a session first
        session_start();
        
        // Set a session variable
        $_SESSION['test'] = 'value';
        
        // Clear the storage to only capture the abort operation
        $this->storage->exchangeArray([]);
        
        // Abort the session
        session_abort();
        
        // Verify the span was created
        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        
        // Check span name
        $this->assertEquals('session.abort', $span->getName());
        
        // Check attributes
        $attributes = $span->getAttributes();
        $this->assertEquals('session_abort', $attributes->get(TraceAttributes::CODE_FUNCTION_NAME));
        
        // Check session information
        $this->assertNotNull($attributes->get('php.session.id'));
        $this->assertNotNull($attributes->get('php.session.name'));
        
        // Check status
        $this->assertEquals(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

}

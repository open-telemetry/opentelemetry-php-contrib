<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use OpenTelemetry\Contrib\Sampler\Xray\RateLimiter;

final class RateLimiterTest extends TestCase
{
    public function testAcquireSuccess(): void
    {
        $limiter = new RateLimiter(3, 1000); // 3 tokens per second
        $this->assertTrue($limiter->tryAcquire());
        $this->assertTrue($limiter->tryAcquire());
        $this->assertTrue($limiter->tryAcquire());
    }

    public function testCannotAcquireAfterCapacity(): void
    {
        $limiter = new RateLimiter(2, 1000);
        $this->assertTrue($limiter->tryAcquire());
        $this->assertTrue($limiter->tryAcquire());
        $this->assertFalse($limiter->tryAcquire(), 'Should not acquire more than capacity');
    }

    public function testRefillAfterInterval(): void
    {
        // Use a short interval for test (100 ms)
        $limiter = new RateLimiter(1, 100);
        $this->assertTrue($limiter->tryAcquire(), 'First acquire should succeed');
        $this->assertFalse($limiter->tryAcquire(), 'Second acquire before refill should fail');

        // Wait >100 ms to allow refill
        usleep(150000); // 150 ms

        $this->assertTrue($limiter->tryAcquire(), 'Acquire after interval should succeed');
    }

    public function testGetCapacity(): void
    {
        $limiter = new RateLimiter(5, 1000);
        $this->assertEquals(5, $limiter->getCapacity());
    }
}

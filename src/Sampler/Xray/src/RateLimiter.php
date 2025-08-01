<?php

declare(strict_types=1);
// src/Sampler/AWS/RateLimiter.php

namespace OpenTelemetry\Contrib\Sampler\Xray;

/**
 * A simple token‐bucket rate limiter.
 */
class RateLimiter
{
    private int $capacity;
    private int $tokens;
    private float $intervalMillis;
    private float $lastRefillTime;

    /**
     * @param int $capacity        Maximum tokens per interval.
     * @param int $intervalMillis  Interval in milliseconds to reset tokens (usually 1000ms).
     */
    public function __construct(int $capacity, int $intervalMillis = 1000)
    {
        $this->capacity       = $capacity;
        $this->tokens         = $capacity;
        $this->intervalMillis = $intervalMillis;
        $this->lastRefillTime = microtime(true) * 1000;
    }

    /**
     * Attempt to take one token. Returns true if successful, false if rate‐limited.
     */
    public function tryAcquire(): bool
    {
        $now     = microtime(true) * 1000;
        $elapsed = $now - $this->lastRefillTime;

        if ($elapsed >= $this->intervalMillis) {
            // Time to refill the bucket
            $this->tokens         = $this->capacity;
            $this->lastRefillTime = $now;
        }

        if ($this->tokens > 0) {
            $this->tokens--;

            return true;
        }

        return false;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }
}

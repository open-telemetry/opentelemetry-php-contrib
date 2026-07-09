<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Context\Revolt;

use OpenTelemetry\Context\Context;
use Revolt\EventLoop;

/** @covers \OpenTelemetry\Contrib\Context\Revolt\RevoltDriver */
final class RevoltDriverTest extends EventLoop\Driver\DriverTest
{
    public function getFactory(): callable
    {
        return static fn () => RevoltDriver::wrap(new EventLoop\Driver\StreamSelectDriver());
    }

    public function testWrapReturnsSameInstanceForSameDriver(): void
    {
        $driver = new EventLoop\Driver\StreamSelectDriver();
        $this->assertSame(RevoltDriver::wrap($driver), RevoltDriver::wrap($driver));
    }

    public function testAdapterPropagatesContextToQueueCallbacks(): void
    {
        $context = Context::getCurrent()->with(Context::createKey('test'), 42);
        $scope = $context->activate();

        $suspension = $this->loop->getSuspension();

        try {
            $this->loop->queue(static fn () => $suspension->resume(Context::getCurrent()));
        } finally {
            $scope->detach();
        }

        $this->assertSame($context, $suspension->suspend());
    }

    public function testAdapterPropagatesContextToDeferCallbacks(): void
    {
        $context = Context::getCurrent()->with(Context::createKey('test'), 42);
        $scope = $context->activate();

        $suspension = $this->loop->getSuspension();

        try {
            $this->loop->defer(static fn () => $suspension->resume(Context::getCurrent()));
        } finally {
            $scope->detach();
        }

        $this->assertSame($context, $suspension->suspend());
    }

    public function testAdapterPropagatesContextToDelayCallbacks(): void
    {
        $context = Context::getCurrent()->with(Context::createKey('test'), 42);
        $scope = $context->activate();

        $suspension = $this->loop->getSuspension();

        try {
            $this->loop->delay(0, static fn () => $suspension->resume(Context::getCurrent()));
        } finally {
            $scope->detach();
        }

        $this->assertSame($context, $suspension->suspend());
    }

    public function testAdapterPropagatesContextToRepeatCallbacks(): void
    {
        $context = Context::getCurrent()->with(Context::createKey('test'), 42);
        $scope = $context->activate();

        $suspension = $this->loop->getSuspension();

        try {
            $callback = $this->loop->repeat(0, static fn () => $suspension->resume(Context::getCurrent()));
        } finally {
            $scope->detach();
        }

        try {
            $this->assertSame($context, $suspension->suspend());
        } finally {
            $this->loop->cancel($callback);
        }
    }
}

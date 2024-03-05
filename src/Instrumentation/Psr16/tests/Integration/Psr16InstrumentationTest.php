<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Psr16\Tests\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

class Psr16InstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );
        $this->scope = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->withPropagator(new TraceContextPropagator())
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_get_key(): void
    {
        $this->assertCount(0, $this->storage);
        $driver = $this->createMemoryCacheDriver();
        $driver->get('foo');
        $this->assertCount(1, $this->storage);
    }

    public function test_set_key(): void
    {
        $this->assertCount(0, $this->storage);
        $driver = $this->createMemoryCacheDriver();
        $driver->set('foo', 'bar');
        $this->assertCount(1, $this->storage);
    }

    private function createMemoryCacheDriver(): CacheInterface
    {
        return new class implements CacheInterface {
            private $data = [];

            public function get($key, $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function set($key, $value, $ttl = null): bool
            {
                $this->data[$key] = $value;
                return true;
            }

            public function delete($key): bool
            {
                unset($this->data[$key]);
                return true;
            }

            public function clear(): bool
            {
                $this->data = [];
                return true;
            }

            public function getMultiple($keys, $default = null): iterable
            {
                $values = [];
                foreach ($keys as $key) {
                    $values[$key] = $this->get($key, $default);
                }
                return $values;
            }

            public function setMultiple($values, $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set($key, $value, $ttl);
                }
                return true;
            }

            public function deleteMultiple($keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete($key);
                }
                return true;
            }

            public function has($key): bool
            {
                return isset($this->data[$key]);
            }
        };
    }
}
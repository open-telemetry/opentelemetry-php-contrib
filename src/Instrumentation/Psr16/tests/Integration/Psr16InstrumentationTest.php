<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Psr16\Tests\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\Psr16\Psr16Instrumentation;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

class Psr16InstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;
    private CacheInterface $adapter;

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
        $this->adapter = $this->createMemoryCacheAdapter();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_get_key(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->get('foo');
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('get', $span->getName());
        $this->assertEquals('psr16', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        $this->assertEquals('get', $span->getAttributes()->get(TraceAttributes::DB_OPERATION));
    }

    public function test_set_key(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->set('foo', 'bar');
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('set', $span->getName());
        $this->assertEquals('psr16', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        $this->assertEquals('set', $span->getAttributes()->get(TraceAttributes::DB_OPERATION));
    }

    public function test_delete_key(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->delete('foo');
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('delete', $span->getName());
        $this->assertEquals('psr16', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        $this->assertEquals('delete', $span->getAttributes()->get(TraceAttributes::DB_OPERATION));
    }

    public function test_clear_keys(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->clear();
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('clear', $span->getName());
        $this->assertEquals('psr16', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        $this->assertEquals('clear', $span->getAttributes()->get(TraceAttributes::DB_OPERATION));
    }

    public function test_get_multiple_keys(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->getMultiple(['foo', 'bar']);
        $this->assertCount(3, $this->storage);
        /** @var ImmutableSpan $spanOne, $spanTwo, $spanThree */
        $spanOne = $this->storage->offsetGet(0);
        $spanTwo = $this->storage->offsetGet(1);
        $spanThree = $this->storage->offsetGet(2);
        $this->assertEquals('get', $spanOne->getName());
        $this->assertEquals('get', $spanTwo->getName());
        $this->assertEquals('getMultiple', $spanThree->getName());
        $this->assertEquals('psr16', $spanOne->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        $this->assertEquals('psr16', $spanTwo->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        $this->assertEquals('psr16', $spanThree->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        $this->assertEquals('get', $spanOne->getAttributes()->get(TraceAttributes::DB_OPERATION));
        $this->assertEquals('get', $spanTwo->getAttributes()->get(TraceAttributes::DB_OPERATION));
        $this->assertEquals('getMultiple', $spanThree->getAttributes()->get(TraceAttributes::DB_OPERATION));
    }

    public function test_set_multiple_keys(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->setMultiple(['foo' => 'bar']);
        $this->assertCount(2, $this->storage);
        /** @var ImmutableSpan $spanOne, $spanTwo */
        $spanOne = $this->storage->offsetGet(0);
        $spanTwo = $this->storage->offsetGet(1);
        $this->assertEquals('set', $spanOne->getName());
        $this->assertEquals('setMultiple', $spanTwo->getName());
        $this->assertEquals('psr16', $spanOne->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        $this->assertEquals('psr16', $spanTwo->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        $this->assertEquals('set', $spanOne->getAttributes()->get(TraceAttributes::DB_OPERATION));
        $this->assertEquals('setMultiple', $spanTwo->getAttributes()->get(TraceAttributes::DB_OPERATION));
    }

    public function test_delete_multiple_keys(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->deleteMultiple(['foo', 'bar']);
        $this->assertCount(3, $this->storage);
        /** @var ImmutableSpan $spanOne, $spanTwo, $spanThree */
        $spanOne = $this->storage->offsetGet(0);
        $spanTwo = $this->storage->offsetGet(1);
        $spanThree = $this->storage->offsetGet(2);
        $this->assertEquals('delete', $spanOne->getName());
        $this->assertEquals('delete', $spanTwo->getName());
        $this->assertEquals('deleteMultiple', $spanThree->getName());
        $this->assertEquals('psr16', $spanOne->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        $this->assertEquals('psr16', $spanTwo->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        $this->assertEquals('psr16', $spanThree->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        $this->assertEquals('delete', $spanOne->getAttributes()->get(TraceAttributes::DB_OPERATION));
        $this->assertEquals('delete', $spanTwo->getAttributes()->get(TraceAttributes::DB_OPERATION));
        $this->assertEquals('deleteMultiple', $spanThree->getAttributes()->get(TraceAttributes::DB_OPERATION));
    }

    public function test_has_key(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->has('foo');
        $this->assertCount(2, $this->storage);
        /** @var ImmutableSpan $spanOne, $spanTwo */
        $spanOne = $this->storage->offsetGet(0);
        $spanTwo = $this->storage->offsetGet(1);
        $this->assertEquals('get', $spanOne->getName());
        $this->assertEquals('has', $spanTwo->getName());
        $this->assertEquals('psr16', $spanOne->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        $this->assertEquals('psr16', $spanTwo->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        $this->assertEquals('get', $spanOne->getAttributes()->get(TraceAttributes::DB_OPERATION));
        $this->assertEquals('has', $spanTwo->getAttributes()->get(TraceAttributes::DB_OPERATION));
    }

    public function test_can_register(): void
    {
        $this->expectNotToPerformAssertions();

        Psr16Instrumentation::register();
    }

    private function createMemoryCacheAdapter(): CacheInterface
    {
        return new class() implements CacheInterface {
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
                return !empty($this->get($key));
            }
        };
    }
}

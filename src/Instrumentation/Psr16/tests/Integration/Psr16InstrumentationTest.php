<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Psr16\Tests\Integration;

use ArrayObject;
use DateInterval;
use DateTime;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\Psr16\Psr16Instrumentation;
use OpenTelemetry\SDK\Trace\Event;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
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
        $this->assertEquals('get', $span->getAttributes()->get('cache.operation'));
        $this->assertEquals('foo', $span->getAttributes()->get('cache.key'));
    }

    public function test_get_key_invalid(): void
    {
        
        try {
            $this->adapter->get('');
        } catch (\Throwable $ex) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $ex);
        }
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('get', $span->getName());
        /** @var Event $event */
        $event = $span->getEvents()[0];
        $this->assertSame('exception', $event->getName());
        $this->assertSame(\InvalidArgumentException::class, $event->getAttributes()->get('exception.type'));
        $this->assertSame('cache key is empty', $event->getAttributes()->get('exception.message'));
    }

    public function test_set_key(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->set('foo', 'bar');
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('set', $span->getName());
        $this->assertEquals('set', $span->getAttributes()->get('cache.operation'));
        $this->assertEquals('foo', $span->getAttributes()->get('cache.key'));
    }

    public function test_delete_key(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->delete('foo');
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('delete', $span->getName());
        $this->assertEquals('delete', $span->getAttributes()->get('cache.operation'));
        $this->assertEquals('foo', $span->getAttributes()->get('cache.key'));
    }

    public function test_clear_keys(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->clear();
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('clear', $span->getName());
        $this->assertEquals('clear', $span->getAttributes()->get('cache.operation'));
        $this->assertNull($span->getAttributes()->get('cache.key'));
        $this->assertNull($span->getAttributes()->get('cache.keys'));
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
        $this->assertEquals('get', $spanOne->getAttributes()->get('cache.operation'));
        $this->assertEquals('get', $spanTwo->getAttributes()->get('cache.operation'));
        $this->assertEquals('getMultiple', $spanThree->getAttributes()->get('cache.operation'));
        $this->assertEquals('foo', $spanOne->getAttributes()->get('cache.key'));
        $this->assertEquals('bar', $spanTwo->getAttributes()->get('cache.key'));
        $this->assertEquals('foo,bar', $spanThree->getAttributes()->get('cache.keys'));
    }

    public function test_set_multiple_keys(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->setMultiple(['foo' => 'bar', 'baz' => 'baa']);
        $this->assertCount(3, $this->storage);
        /** @var ImmutableSpan $spanOne, $spanTwo */
        $spanOne = $this->storage->offsetGet(0);
        $spanTwo = $this->storage->offsetGet(1);
        $spanThree = $this->storage->offsetGet(2);
        $this->assertEquals('set', $spanOne->getName());
        $this->assertEquals('set', $spanTwo->getName());
        $this->assertEquals('setMultiple', $spanThree->getName());
        $this->assertEquals('set', $spanOne->getAttributes()->get('cache.operation'));
        $this->assertEquals('set', $spanTwo->getAttributes()->get('cache.operation'));
        $this->assertEquals('setMultiple', $spanThree->getAttributes()->get('cache.operation'));
        $this->assertEquals('foo', $spanOne->getAttributes()->get('cache.key'));
        $this->assertEquals('baz', $spanTwo->getAttributes()->get('cache.key'));
        $this->assertEquals('foo,baz', $spanThree->getAttributes()->get('cache.keys'));
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
        $this->assertEquals('delete', $spanOne->getAttributes()->get('cache.operation'));
        $this->assertEquals('delete', $spanTwo->getAttributes()->get('cache.operation'));
        $this->assertEquals('deleteMultiple', $spanThree->getAttributes()->get('cache.operation'));
        $this->assertEquals('foo', $spanOne->getAttributes()->get('cache.key'));
        $this->assertEquals('bar', $spanTwo->getAttributes()->get('cache.key'));
        $this->assertEquals('foo,bar', $spanThree->getAttributes()->get('cache.keys'));
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
        $this->assertEquals('get', $spanOne->getAttributes()->get('cache.operation'));
        $this->assertEquals('has', $spanTwo->getAttributes()->get('cache.operation'));
        $this->assertEquals('foo', $spanOne->getAttributes()->get('cache.key'));
        $this->assertEquals('foo', $spanTwo->getAttributes()->get('cache.key'));
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

            protected function checkKey(string $key):string
            {
                if (empty($key)) {
                    throw new \InvalidArgumentException('cache key is empty');
                }
        
                return $key;
            }

            protected function getTTL(DateInterval|int|null $ttl):?int
            {
                if ($ttl instanceof DateInterval) {
                    return ((new DateTime())->add($ttl)->getTimeStamp() - time());
                }
        
                if ((is_int($ttl) && $ttl > 0)) {
                    return $ttl;
                }
        
                return null;
            }

            protected function fromIterable(iterable $data): array
            {
                if (is_array($data)) {
                    return $data;
                }
        
                return iterator_to_array($data);
            }

            protected function checkReturn(array $booleans): bool
            {
                foreach ($booleans as $boolean) {
                    if (!(bool) $boolean) {
                        return false;
                    }
                }

                return true;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                $key = $this->checkKey($key);

                if (isset($this->data[$key])) {
                    if ($this->data[$key]['ttl'] === null || $this->data[$key]['ttl'] > time()) {
                        return $this->data[$key]['content'];
                    }

                    unset($this->data[$key]);
                }

                return $default;
            }

            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                $ttl = $this->getTTL($ttl);

                if ($ttl !== null) {
                    $ttl = (time() + $ttl);
                }

                $this->data[$this->checkKey($key)] = ['ttl' => $ttl, 'content' => $value];

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->data[$this->checkKey($key)]);

                return true;
            }

            public function clear(): bool
            {
                $this->data = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $data = [];

                foreach ($this->fromIterable($keys) as $key) {
                    $data[$key] = $this->get($key, $default);
                }

                return $data;
            }

            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
            {
                $return = [];

                foreach ($this->fromIterable($values) as $key => $value) {
                    $return[] = $this->set($key, $value, $ttl);
                }

                return $this->checkReturn($return);
            }

            public function deleteMultiple(iterable $keys): bool
            {
                $return = [];

                foreach ($this->fromIterable($keys) as $key) {
                    $return[] = $this->delete($key);
                }

                return $this->checkReturn($return);
            }

            public function has(string $key): bool
            {
                return !empty($this->get($key));
            }
        };
    }
}

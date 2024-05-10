<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Psr6\Tests\Integration;

use ArrayObject;
use DateTimeImmutable;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\Psr6\Psr6Instrumentation;
use OpenTelemetry\SDK\Trace\Event;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class Psr6InstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;
    private CacheItemPoolInterface $adapter;

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

    public function test_get_item(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->getItem('foo');
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('getItem', $span->getName());
        $this->assertEquals('getItem', $span->getAttributes()->get('cache.operation'));
        $this->assertEquals('foo', $span->getAttributes()->get('cache.key'));
    }

    public function test_get_item_with_key_invalid(): void
    {
        try {
            $this->adapter->getItem('');
        } catch (\Throwable $ex) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $ex);
        }
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('getItem', $span->getName());
        /** @var Event $event */
        $event = $span->getEvents()[0];
        $this->assertSame('exception', $event->getName());
        $this->assertSame(\InvalidArgumentException::class, $event->getAttributes()->get('exception.type'));
        $this->assertSame('cache key is empty', $event->getAttributes()->get('exception.message'));
    }

    public function test_get_items(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->getItems(['foo', 'bar']);
        $this->assertCount(3, $this->storage);
        /** @var ImmutableSpan $spanOne, $spanTwo, $spanThree */
        $spanOne = $this->storage->offsetGet(0);
        $spanTwo = $this->storage->offsetGet(1);
        $spanThree = $this->storage->offsetGet(2);
        $this->assertEquals('getItem', $spanOne->getName());
        $this->assertEquals('getItem', $spanTwo->getName());
        $this->assertEquals('getItems', $spanThree->getName());
        $this->assertEquals('getItem', $spanOne->getAttributes()->get('cache.operation'));
        $this->assertEquals('getItem', $spanTwo->getAttributes()->get('cache.operation'));
        $this->assertEquals('getItems', $spanThree->getAttributes()->get('cache.operation'));
        $this->assertEquals('foo', $spanOne->getAttributes()->get('cache.key'));
        $this->assertEquals('bar', $spanTwo->getAttributes()->get('cache.key'));
        $this->assertEquals('foo,bar', $spanThree->getAttributes()->get('cache.keys'));
    }

    public function test_has_item(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->hasItem('foo');
        $this->assertCount(2, $this->storage);
        /** @var ImmutableSpan $spanOne, $spanTwo */
        $spanOne = $this->storage->offsetGet(0);
        $spanTwo = $this->storage->offsetGet(1);
        $this->assertEquals('getItem', $spanOne->getName());
        $this->assertEquals('hasItem', $spanTwo->getName());
        $this->assertEquals('getItem', $spanOne->getAttributes()->get('cache.operation'));
        $this->assertEquals('hasItem', $spanTwo->getAttributes()->get('cache.operation'));
        $this->assertEquals('foo', $spanOne->getAttributes()->get('cache.key'));
        $this->assertEquals('foo', $spanTwo->getAttributes()->get('cache.key'));
    }

    public function test_clear(): void
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

    public function test_delete_item(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->deleteItem('foo');
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('deleteItem', $span->getName());
        $this->assertEquals('deleteItem', $span->getAttributes()->get('cache.operation'));
        $this->assertEquals('foo', $span->getAttributes()->get('cache.key'));
    }

    public function test_delete_items(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->deleteItems(['foo', 'bar']);
        $this->assertCount(3, $this->storage);
        /** @var ImmutableSpan $spanOne, $spanTwo, $spanThree */
        $spanOne = $this->storage->offsetGet(0);
        $spanTwo = $this->storage->offsetGet(1);
        $spanThree = $this->storage->offsetGet(2);
        $this->assertEquals('deleteItem', $spanOne->getName());
        $this->assertEquals('deleteItem', $spanTwo->getName());
        $this->assertEquals('deleteItems', $spanThree->getName());
        $this->assertEquals('deleteItem', $spanOne->getAttributes()->get('cache.operation'));
        $this->assertEquals('deleteItem', $spanTwo->getAttributes()->get('cache.operation'));
        $this->assertEquals('deleteItems', $spanThree->getAttributes()->get('cache.operation'));
        $this->assertEquals('foo', $spanOne->getAttributes()->get('cache.key'));
        $this->assertEquals('bar', $spanTwo->getAttributes()->get('cache.key'));
        $this->assertEquals('foo,bar', $spanThree->getAttributes()->get('cache.keys'));
    }

    public function test_save(): void
    {
        $item = new CacheItem('foo', 'bar');
        $this->assertCount(0, $this->storage);
        $this->adapter->save($item);
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('save', $span->getName());
        $this->assertEquals('save', $span->getAttributes()->get('cache.operation'));
        $this->assertEquals('foo', $span->getAttributes()->get('cache.key'));
        $this->assertNull($span->getAttributes()->get('cache.keys'));
    }

    public function test_save_deferred(): void
    {
        $item = new CacheItem('foo', 'bar');
        $this->assertCount(0, $this->storage);
        $this->adapter->saveDeferred($item);
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('saveDeferred', $span->getName());
        $this->assertEquals('saveDeferred', $span->getAttributes()->get('cache.operation'));
        $this->assertEquals('foo', $span->getAttributes()->get('cache.key'));
        $this->assertNull($span->getAttributes()->get('cache.keys'));
    }

    public function test_commit(): void
    {
        $this->assertCount(0, $this->storage);
        $this->adapter->commit();
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('commit', $span->getName());
        $this->assertEquals('commit', $span->getAttributes()->get('cache.operation'));
        $this->assertNull($span->getAttributes()->get('cache.key'));
        $this->assertNull($span->getAttributes()->get('cache.keys'));
    }

    public function test_can_register(): void
    {
        $this->expectNotToPerformAssertions();

        Psr6Instrumentation::register();
    }

    private function createMemoryCacheAdapter(): CacheItemPoolInterface
    {
        return new class() implements CacheItemPoolInterface {
            private $items = [];
            private $deferredItems = [];

            protected function checkKey(string $key):string
            {
                if(empty($key)) {
                    throw new \InvalidArgumentException('cache key is empty');
                }
        
                return $key;
            }

            public function getItem(string $key): CacheItemInterface
            {
                $key = $this->checkKey($key);

                $item = $this->items[$key] ?? null;

                if ($item === null) {
                    return new CacheItem($key);
                }

                return clone $item;
            }

            public function getItems(array $keys = []): iterable
            {
                if ($keys === []) {
                    return [];
                }
        
                $items = [];
        
                foreach ($keys as $key) {
                    $items[$key] = $this->getItem($key);
                }
        
                return $items;
            }

            public function hasItem(string $key): bool
            {
                return $this->getItem($key)->isHit();
            }

            public function clear(): bool
            {
                $this->items = [];
                $this->deferredItems = [];
        
                return true;
            }

            public function deleteItem(string $key): bool
            {
                $key = $this->checkKey($key);

                unset($this->items[$key]);

                return true;
            }

            public function deleteItems(array $keys): bool
            {
                foreach ($keys as $key) {
                    $this->deleteItem($key);
                }

                return true;
            }

            public function save(CacheItemInterface $item): bool
            {
                $this->items[$item->getKey()] = $item;

                return true;
            }

            public function saveDeferred(CacheItemInterface $item): bool
            {
                $this->deferredItems[$item->getKey()] = $item;

                return true;
            }

            public function commit(): bool
            {
                foreach ($this->deferredItems as $item) {
                    $this->save($item);
                }

                $this->deferredItems = [];

                return true;
            }
        };
    }
}

final class CacheItem implements CacheItemInterface
{
    private ?\DateTimeInterface $expiresAt;
    private bool $isHit;

    public function __construct(
        private string $key,
        private mixed $value = null,
    ) {
        $this->key = $key;
        $this->value = $value;
        $this->expiresAt = null;
        $this->isHit = false;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        if ($this->isHit()) {
            return $this->value;
        }

        return null;
    }

    public function isHit(): bool
    {
        if ($this->isHit === false) {
            return false;
        }

        if ($this->expiresAt === null) {
            return true;
        }

        return (new DateTimeImmutable())->getTimestamp() < $this->expiresAt->getTimestamp();
    }

    public function set(mixed $value): static
    {
        $this->isHit = true;
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        $this->expiresAt = $expiration;

        return $this;
    }

    public function expiresAfter(\DateInterval|int|null $time): static
    {
        if ($time === null) {
            $this->expiresAt = null;

            return $this;
        }

        if (is_int($time)) {
            $time = new \DateInterval("PT{$time}S");
        }

        $this->expiresAt = (new DateTimeImmutable())->add($time);

        return $this;
    }
}

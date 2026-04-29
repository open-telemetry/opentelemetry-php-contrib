<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Psr6\Tests\Unit;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\Psr6\Psr6Instrumentation;
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

    public function test_name_constant(): void
    {
        $this->assertSame('psr6', Psr6Instrumentation::NAME);
    }

    public function test_register(): void
    {
        $this->expectNotToPerformAssertions();
        Psr6Instrumentation::register();
    }

    public function test_get_item_creates_span_with_key(): void
    {
        $this->adapter->getItem('test-key');
        $this->assertGreaterThanOrEqual(1, count($this->storage));

        $span = $this->findSpan('getItem');
        $this->assertNotNull($span);
        $this->assertSame('getItem', $span->getAttributes()->get('cache.operation'));
        $this->assertSame('test-key', $span->getAttributes()->get('cache.key'));
        $this->assertNull($span->getAttributes()->get('cache.keys'));
    }

    public function test_get_items_creates_span_with_keys(): void
    {
        $this->adapter->getItems(['alpha', 'beta']);

        $span = $this->findSpan('getItems');
        $this->assertNotNull($span);
        $this->assertSame('alpha,beta', $span->getAttributes()->get('cache.keys'));
    }

    public function test_has_item_creates_span(): void
    {
        $this->adapter->hasItem('x');

        $span = $this->findSpan('hasItem');
        $this->assertNotNull($span);
        $this->assertSame('x', $span->getAttributes()->get('cache.key'));
    }

    public function test_clear_creates_span_without_keys(): void
    {
        $this->adapter->clear();

        $span = $this->findSpan('clear');
        $this->assertNotNull($span);
        $this->assertNull($span->getAttributes()->get('cache.key'));
        $this->assertNull($span->getAttributes()->get('cache.keys'));
    }

    public function test_delete_item_creates_span(): void
    {
        $this->adapter->deleteItem('del-key');

        $span = $this->findSpan('deleteItem');
        $this->assertNotNull($span);
        $this->assertSame('del-key', $span->getAttributes()->get('cache.key'));
    }

    public function test_delete_items_creates_span_with_keys(): void
    {
        $this->adapter->deleteItems(['a', 'b']);

        $span = $this->findSpan('deleteItems');
        $this->assertNotNull($span);
        $this->assertSame('a,b', $span->getAttributes()->get('cache.keys'));
    }

    public function test_save_creates_span_with_cache_item_key(): void
    {
        $item = new TestCacheItem('save-key', 'value');
        $this->adapter->save($item);

        $span = $this->findSpan('save');
        $this->assertNotNull($span);
        $this->assertSame('save-key', $span->getAttributes()->get('cache.key'));
    }

    public function test_save_deferred_creates_span(): void
    {
        $item = new TestCacheItem('deferred-key', 'value');
        $this->adapter->saveDeferred($item);

        $span = $this->findSpan('saveDeferred');
        $this->assertNotNull($span);
        $this->assertSame('deferred-key', $span->getAttributes()->get('cache.key'));
    }

    public function test_commit_creates_span(): void
    {
        $this->adapter->commit();

        $span = $this->findSpan('commit');
        $this->assertNotNull($span);
        $this->assertNull($span->getAttributes()->get('cache.key'));
    }

    public function test_exception_records_error_on_span(): void
    {
        try {
            $this->adapter->getItem('');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
        }

        $span = $this->findSpan('getItem');
        $this->assertNotNull($span);
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertNotEmpty($span->getEvents());
        $this->assertSame('exception', $span->getEvents()[0]->getName());
    }

    public function test_span_has_code_attributes(): void
    {
        $this->adapter->getItem('foo');

        $span = $this->findSpan('getItem');
        $this->assertNotNull($span);
        $this->assertNotNull($span->getAttributes()->get('code.function.name'));
        $this->assertStringContainsString('getItem', $span->getAttributes()->get('code.function.name'));
    }

    /**
     * Find the first span with the given name.
     */
    private function findSpan(string $name): ?ImmutableSpan
    {
        foreach ($this->storage as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        return null;
    }

    private function createMemoryCacheAdapter(): CacheItemPoolInterface
    {
        return new class() implements CacheItemPoolInterface {
            private array $items = [];
            private array $deferredItems = [];

            public function getItem(string $key): CacheItemInterface
            {
                if (empty($key)) {
                    throw new \InvalidArgumentException('cache key is empty');
                }

                return $this->items[$key] ?? new TestCacheItem($key);
            }

            public function getItems(array $keys = []): iterable
            {
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

final class TestCacheItem implements CacheItemInterface
{
    public function __construct(
        private string $key,
        private mixed $value = null,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return false;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        return $this;
    }

    public function expiresAfter(\DateInterval|int|null $time): static
    {
        return $this;
    }
}

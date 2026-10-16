<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Psr16\Tests\Unit;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\Psr16\Psr16Instrumentation;
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
    private CacheInterface $adapter;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );
        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
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
        $this->assertSame('psr16', Psr16Instrumentation::NAME);
    }

    public function test_register(): void
    {
        $this->expectNotToPerformAssertions();
        Psr16Instrumentation::register();
    }

    public function test_get_creates_span_with_key(): void
    {
        $this->adapter->get('my-key');

        $span = $this->findSpan('get');
        $this->assertNotNull($span);
        $this->assertSame('get', $span->getAttributes()->get('cache.operation'));
        $this->assertSame('my-key', $span->getAttributes()->get('cache.key'));
    }

    public function test_set_creates_span_with_key(): void
    {
        $this->adapter->set('set-key', 'value');

        $span = $this->findSpan('set');
        $this->assertNotNull($span);
        $this->assertSame('set-key', $span->getAttributes()->get('cache.key'));
    }

    public function test_delete_creates_span(): void
    {
        $this->adapter->delete('del-key');

        $span = $this->findSpan('delete');
        $this->assertNotNull($span);
        $this->assertSame('del-key', $span->getAttributes()->get('cache.key'));
    }

    public function test_clear_creates_span_without_keys(): void
    {
        $this->adapter->clear();

        $span = $this->findSpan('clear');
        $this->assertNotNull($span);
        $this->assertNull($span->getAttributes()->get('cache.key'));
        $this->assertNull($span->getAttributes()->get('cache.keys'));
    }

    public function test_get_multiple_creates_span_with_keys(): void
    {
        $this->adapter->getMultiple(['x', 'y']);

        $span = $this->findSpan('getMultiple');
        $this->assertNotNull($span);
        $this->assertSame('x,y', $span->getAttributes()->get('cache.keys'));
    }

    public function test_set_multiple_creates_span_with_keys(): void
    {
        $this->adapter->setMultiple(['foo' => 'bar', 'baz' => 'qux']);

        $span = $this->findSpan('setMultiple');
        $this->assertNotNull($span);
        $this->assertSame('foo,baz', $span->getAttributes()->get('cache.keys'));
    }

    public function test_delete_multiple_creates_span_with_keys(): void
    {
        $this->adapter->deleteMultiple(['a', 'b']);

        $span = $this->findSpan('deleteMultiple');
        $this->assertNotNull($span);
        $this->assertSame('a,b', $span->getAttributes()->get('cache.keys'));
    }

    public function test_has_creates_span(): void
    {
        $this->adapter->has('check-key');

        $span = $this->findSpan('has');
        $this->assertNotNull($span);
        $this->assertSame('check-key', $span->getAttributes()->get('cache.key'));
    }

    public function test_exception_records_error_on_span(): void
    {
        try {
            $this->adapter->get('');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
        }

        $span = $this->findSpan('get');
        $this->assertNotNull($span);
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertNotEmpty($span->getEvents());
        $this->assertSame('exception', $span->getEvents()[0]->getName());
    }

    public function test_span_has_code_attributes(): void
    {
        $this->adapter->get('foo');

        $span = $this->findSpan('get');
        $this->assertNotNull($span);
        $this->assertNotNull($span->getAttributes()->get('code.function.name'));
        $this->assertStringContainsString('get', $span->getAttributes()->get('code.function.name'));
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

    private function createMemoryCacheAdapter(): CacheInterface
    {
        return new class() implements CacheInterface {
            private array $data = [];

            public function get(string $key, mixed $default = null): mixed
            {
                if (empty($key)) {
                    throw new \InvalidArgumentException('cache key is empty');
                }

                return $this->data[$key]['content'] ?? $default;
            }

            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                $this->data[$key] = ['ttl' => null, 'content' => $value];

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->data[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->data = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $result = [];
                foreach ($keys as $key) {
                    $result[$key] = $this->get($key, $default);
                }

                return $result;
            }

            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set($key, $value, $ttl);
                }

                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete($key);
                }

                return true;
            }

            public function has(string $key): bool
            {
                return !empty($this->get($key));
            }
        };
    }
}

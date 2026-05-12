<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Magento2\Unit;

use ArrayObject;
use Magento\Framework\Event\InvokerInterface;
use Magento\Framework\Event\Observer;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class InvokerInterfaceTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<array-key, mixed> */
    private ArrayObject $storage;
    /** @var InvokerInterface&MockObject */
    private InvokerInterface $invoker;

    #[Override]
    protected function setUp(): void
    {
        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->activate();

        $this->invoker = $this->createMock(InvokerInterface::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_dispatch_records_observer_span_name_and_code_attributes(): void
    {
        $configuration = ['name' => 'checkout_cart_product_add_after'];
        $observer = $this->createMock(Observer::class);

        $this->invoker->expects($this->once())
            ->method('dispatch')
            ->with($configuration, $observer);

        $this->invoker->dispatch($configuration, $observer);

        $this->assertCount(1, $this->storage);
        $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);
        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $this->assertSame('OBSERVER: checkout_cart_product_add_after', $span->getName());

        $attrs = $span->getAttributes()->toArray();
        $this->assertArrayHasKey(CodeAttributes::CODE_FUNCTION_NAME, $attrs);
        $this->assertNotEmpty($attrs[CodeAttributes::CODE_FUNCTION_NAME]);
        $this->assertArrayHasKey(CodeAttributes::CODE_FILE_PATH, $attrs);
        $this->assertNotEmpty($attrs[CodeAttributes::CODE_FILE_PATH]);
        $this->assertArrayHasKey(CodeAttributes::CODE_LINE_NUMBER, $attrs);
        $this->assertNotEmpty($attrs[CodeAttributes::CODE_LINE_NUMBER]);
    }

    public function test_dispatch_uses_unknown_name_when_configuration_name_is_missing(): void
    {
        $configuration = [];
        $observer = $this->createMock(Observer::class);

        $this->invoker->expects($this->once())
            ->method('dispatch')
            ->with($configuration, $observer);

        $this->invoker->dispatch($configuration, $observer);

        $this->assertCount(1, $this->storage);
        $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);
        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $this->assertSame('OBSERVER: unknown', $span->getName());
    }
}

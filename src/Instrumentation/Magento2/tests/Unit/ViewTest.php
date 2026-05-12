<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Magento2\Unit;

use ArrayObject;
use Magento\Framework\App\View;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\Event;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<array-key, mixed> */
    private ArrayObject $storage;
    /** @var View&MockObject */
    private View $view;

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

        $this->view = $this->getMockBuilder(View::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['renderLayout'])
            ->getMock();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_render_layout_records_layout_span_and_code_attributes(): void
    {
        $this->view->expects($this->once())
            ->method('renderLayout')
            ->willReturn($this->view);

        $result = $this->view->renderLayout();

        $this->assertSame($this->view, $result);
        $this->assertCount(1, $this->storage);
        $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);
        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $this->assertSame('LAYOUT: layout_render', $span->getName());
        $this->assertCount(0, $span->getEvents());

        $attrs = $span->getAttributes()->toArray();
        $this->assertArrayHasKey(CodeAttributes::CODE_FUNCTION_NAME, $attrs);
        $this->assertNotEmpty($attrs[CodeAttributes::CODE_FUNCTION_NAME]);
        $this->assertArrayHasKey(CodeAttributes::CODE_FILE_PATH, $attrs);
        $this->assertNotEmpty($attrs[CodeAttributes::CODE_FILE_PATH]);
        $this->assertArrayHasKey(CodeAttributes::CODE_LINE_NUMBER, $attrs);
        $this->assertNotEmpty($attrs[CodeAttributes::CODE_LINE_NUMBER]);
    }

    public function test_render_layout_records_exception_event_when_render_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Layout render failed');

        $this->view->expects($this->once())
            ->method('renderLayout')
            ->willThrowException(new \RuntimeException('Layout render failed'));

        try {
            $this->view->renderLayout();
        } finally {
            $this->assertCount(1, $this->storage);
            $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);
            /** @var ImmutableSpan $span */
            $span = $this->storage[0];

            $this->assertCount(1, $span->getEvents());
            $this->assertInstanceOf(Event::class, $span->getEvents()[0]);
            $event = $span->getEvents()[0];
            $this->assertSame('exception', $event->getName());

            $eventAttrs = $event->getAttributes()->toArray();
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_TYPE, $eventAttrs);
            $this->assertStringContainsString('RuntimeException', (string) $eventAttrs[ExceptionAttributes::EXCEPTION_TYPE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_MESSAGE, $eventAttrs);
            $this->assertSame('Layout render failed', $eventAttrs[ExceptionAttributes::EXCEPTION_MESSAGE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_STACKTRACE, $eventAttrs);
            $this->assertNotEmpty($eventAttrs[ExceptionAttributes::EXCEPTION_STACKTRACE]);
        }
    }
}

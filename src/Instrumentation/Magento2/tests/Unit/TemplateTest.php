<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Magento2\Unit;

use ArrayObject;
use Magento\Framework\View\Element\Template;
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

final class TemplateTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<array-key, mixed> */
    private ArrayObject $storage;
    /** @var Template&MockObject */
    private Template $template;

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

        $this->template = $this->getMockBuilder(Template::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchView'])
            ->getMock();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_fetch_view_records_template_span_and_code_attributes(): void
    {
        $templateFile = '/var/www/html/vendor/magento/theme/templates/product/view.phtml';
        $this->template->expects($this->once())
            ->method('fetchView')
            ->with($templateFile)
            ->willReturn('<html/>');

        $result = $this->template->fetchView($templateFile);

        $this->assertSame('<html/>', $result);
        $this->assertCount(1, $this->storage);
        $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);
        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $this->assertSame('TEMPLATE: ' . $templateFile, $span->getName());
        $this->assertCount(0, $span->getEvents());

        $attrs = $span->getAttributes()->toArray();
        $this->assertArrayHasKey(CodeAttributes::CODE_FUNCTION_NAME, $attrs);
        $this->assertNotEmpty($attrs[CodeAttributes::CODE_FUNCTION_NAME]);
        $this->assertArrayHasKey(CodeAttributes::CODE_FILE_PATH, $attrs);
        $this->assertNotEmpty($attrs[CodeAttributes::CODE_FILE_PATH]);
        $this->assertArrayHasKey(CodeAttributes::CODE_LINE_NUMBER, $attrs);
        $this->assertNotEmpty($attrs[CodeAttributes::CODE_LINE_NUMBER]);
    }

    public function test_fetch_view_records_exception_event_when_rendering_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Template rendering failed');

        $this->template->expects($this->once())
            ->method('fetchView')
            ->willThrowException(new \RuntimeException('Template rendering failed'));

        try {
            $this->template->fetchView('failing-template.phtml');
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
            $this->assertSame('Template rendering failed', $eventAttrs[ExceptionAttributes::EXCEPTION_MESSAGE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_STACKTRACE, $eventAttrs);
            $this->assertNotEmpty($eventAttrs[ExceptionAttributes::EXCEPTION_STACKTRACE]);
        }
    }
}

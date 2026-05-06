<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Magento2\Unit;

use ArrayObject;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
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

/**
 * Tests for the Action::dispatch hook in Magento2Instrumentation.
 *
 * The instrumentation registers a hook on \Magento\Framework\App\Action\Action::dispatch.
 * Because Action is abstract we derive a concrete test double via getMockForAbstractClass(),
 * which auto-stubs the only abstract method (execute()) while leaving the concrete
 * dispatch() body intact so the OTel hook fires normally.
 *
 * The hook's pre-closure:
 *   - Creates a span named after getFullActionName() ?? 'unknown'
 *   - Attaches code attributes (function name, file path, line number)
 *
 * The hook's post-closure:
 *   - Records any exception thrown during dispatch and sets span status to ERROR
 *   - Ends the span unconditionally
 *
 * Property injection via reflection:
 *   Action::dispatch accesses $this->_response and $this->_actionFlag. Because the
 *   constructor is disabled to skip complex dependency setup, injectProperty() walks
 *   the class hierarchy and sets these fields directly.
 *
 * Span ordering (SimpleSpanProcessor exports on end()):
 *   - isDispatched()=false: 1 span – storage[0] = Action::dispatch span
 *   - isDispatched()=true + execute() throws: 2 spans –
 *       storage[0] = ActionInterface.execute (inner, ends first)
 *       storage[last] = Action::dispatch (outer, ends second)
 *
 * @see \OpenTelemetry\Contrib\Instrumentation\Magento2\Magento2Instrumentation
 */
final class ActionTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<array-key, mixed> */
    private ArrayObject $storage;

    /**
     * @psalm-suppress DeprecatedClass
     * @var Action&MockObject
     */
    private Action $action;

    /** @var HttpRequest&MockObject */
    private HttpRequest $httpRequest;

    /** @var HttpResponse&MockObject */
    private HttpResponse $httpResponse;

    /** @var ActionFlag&MockObject */
    private ActionFlag $actionFlag;

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

        $this->httpRequest = $this->getMockBuilder(HttpRequest::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->httpResponse = $this->createMock(HttpResponse::class);

        $this->actionFlag = $this->createMock(ActionFlag::class);

        // Concrete subclass of the abstract Action class; execute() is auto-stubbed.
        /** @psalm-suppress DeprecatedClass */
        /** @var Action&MockObject $action */
        $action = $this->getMockBuilder(Action::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        // Inject the minimum properties that Action::dispatch() accesses.
        self::injectProperty($action, '_response', $this->httpResponse);
        self::injectProperty($action, '_actionFlag', $this->actionFlag);

        $this->action = $action;
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Walk up the class hierarchy until the named property is found, then set it.
     *
     * Required because Action::dispatch reads _response and _actionFlag, which are
     * declared in parent classes and cannot be set via the disabled constructor.
     */
    private static function injectProperty(object $object, string $name, mixed $value): void
    {
        $ref = new \ReflectionClass($object);
        while (!$ref->hasProperty($name)) {
            $ref = $ref->getParentClass();
            if ($ref === false) {
                throw new \LogicException("Property {$name} not found in class hierarchy.");
            }
        }
        $ref->getProperty($name)->setValue($object, $value);
    }

    /**
     * Assert exactly one span was exported and return it.
     */
    private function getSingleSpan(): ImmutableSpan
    {
        $this->assertCount(1, $this->storage, 'Expected exactly one span to be exported.');
        $span = $this->storage[0];
        $this->assertInstanceOf(ImmutableSpan::class, $span);

        return $span;
    }

    /**
     * @test Span name is set to the value returned by getFullActionName().
     *
     * When the first argument to dispatch() is an HttpRequest the pre-hook captures
     * $params[0]->getFullActionName() as the span name.
     * isDispatched()=false prevents execute() from being called, so only one span is emitted.
     */
    public function test_dispatch_span_name_uses_full_action_name(): void
    {
        $this->httpRequest->method('isDispatched')->willReturn(false);
        $this->httpRequest->method('getFullActionName')->willReturn('catalog_product_view');

        $this->action->dispatch($this->httpRequest);

        $this->assertSame('catalog_product_view', $this->getSingleSpan()->getName());
    }

    /**
     * @test Span name falls back to 'unknown' when getFullActionName() returns null.
     *
     * The pre-hook uses the null-coalescing operator: $request?->getFullActionName() ?? 'unknown'.
     * A null return therefore produces 'unknown' as the span name.
     */
    public function test_dispatch_span_name_falls_back_to_unknown_when_null(): void
    {
        $this->httpRequest->method('isDispatched')->willReturn(false);
        $this->httpRequest->method('getFullActionName')->willReturn(null);

        $this->action->dispatch($this->httpRequest);

        $this->assertSame('unknown', $this->getSingleSpan()->getName());
    }

    /**
     * @test Code attributes (function name, file path, line number) are always present.
     *
     * The pre-hook sets CodeAttributes::CODE_FUNCTION_NAME, CODE_FILE_PATH, and
     * CODE_LINE_NUMBER unconditionally on every dispatch span.
     */
    public function test_dispatch_records_code_attributes(): void
    {
        $this->httpRequest->method('isDispatched')->willReturn(false);
        $this->httpRequest->method('getFullActionName')->willReturn('checkout_cart_index');

        $this->action->dispatch($this->httpRequest);

        $attrs = $this->getSingleSpan()->getAttributes()->toArray();

        $this->assertArrayHasKey(CodeAttributes::CODE_FUNCTION_NAME, $attrs);
        $this->assertNotEmpty($attrs[CodeAttributes::CODE_FUNCTION_NAME]);

        $this->assertArrayHasKey(CodeAttributes::CODE_FILE_PATH, $attrs);
        $this->assertNotEmpty($attrs[CodeAttributes::CODE_FILE_PATH]);

        $this->assertArrayHasKey(CodeAttributes::CODE_LINE_NUMBER, $attrs);
        $this->assertNotEmpty($attrs[CodeAttributes::CODE_LINE_NUMBER]);
    }

    /**
     * @test When execute() throws, the post-hook records the exception on the dispatch span.
     *
     * Setup:
     *   - isDispatched()=true and actionFlag::get()=false so execute() is called
     *   - execute() is stubbed to throw RuntimeException
     *
     * Span ordering after the throw:
     *   storage[0]    = ActionInterface.execute span (inner, exception recorded)
     *   storage[last] = Action::dispatch span  (outer, exception also recorded here)
     *
     * The test asserts the outer Action::dispatch span (storage[last]) carries:
     *   - exception.type    containing 'RuntimeException'
     *   - exception.message = 'Something went wrong'
     *   - exception.stacktrace (non-empty)
     *
     * The try/finally pattern is used so assertions run even though the exception
     * propagates out of dispatch().
     */
    public function test_dispatch_records_exception_on_span(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Something went wrong');

        // Allow execute() to be called by returning true from isDispatched()
        // and false (don't skip dispatch) from actionFlag.
        $this->httpRequest->method('isDispatched')->willReturn(true);
        $this->httpRequest->method('getFullActionName')->willReturn('some_module_action');
        $this->actionFlag->method('get')->willReturn(false);

        // Make execute() throw.
        $this->action->method('execute')->willThrowException(new \RuntimeException('Something went wrong'));

        try {
            $this->action->dispatch($this->httpRequest);
        } finally {
            // Storage is populated even when an exception propagates.
            $this->assertGreaterThanOrEqual(2, count($this->storage));

            // The outer Action::dispatch span is at the last position.
            /** @var ImmutableSpan $dispatchSpan */
            $dispatchSpan = $this->storage[count($this->storage) - 1];
            $this->assertInstanceOf(ImmutableSpan::class, $dispatchSpan);
            $this->assertStringContainsString('some_module_action', $dispatchSpan->getName());

            $events = $dispatchSpan->getEvents();
            $this->assertCount(1, $events, 'Expected one exception event on the dispatch span.');

            /** @var Event $event */
            $event = $events[0];
            $this->assertSame('exception', $event->getName());

            $eventAttrs = $event->getAttributes()->toArray();
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_TYPE, $eventAttrs);
            $this->assertStringContainsString('RuntimeException', (string) $eventAttrs[ExceptionAttributes::EXCEPTION_TYPE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_MESSAGE, $eventAttrs);
            $this->assertSame('Something went wrong', $eventAttrs[ExceptionAttributes::EXCEPTION_MESSAGE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_STACKTRACE, $eventAttrs);
            $this->assertNotEmpty($eventAttrs[ExceptionAttributes::EXCEPTION_STACKTRACE]);
        }
    }
}

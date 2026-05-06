<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Magento2\Unit;

use ArrayObject;
use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Http\Context;
use Magento\Framework\App\Response\Http;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
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
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ActionInterface::execute instrumentation hook in Magento2Instrumentation.
 *
 * The hook registers against \Magento\Framework\App\ActionInterface::execute — an interface
 * method — so it fires for ANY class implementing ActionInterface, regardless of inheritance.
 *
 * The hook's pre-closure:
 *   - Creates a span named 'ActionInterface.execute'
 *   - Attaches code attributes (function name, file path, line number)
 *
 * The hook's post-closure:
 *   - Records any exception thrown during execute() and sets span status to ERROR
 *   - Ends the span unconditionally
 *
 * Test doubles:
 *   - Success path  – uses a real Forward instance (extends AbstractAction, implements
 *     ActionInterface). Forward::execute() calls $request->setDispatched(false) and returns
 *     the response; the OTel hook fires because execute() is the hooked interface method.
 *   - Exception path – uses an anonymous class implementing ActionInterface directly,
 *     so execute() throws without any Magento bootstrap overhead.
 *
 * Span ordering (SimpleSpanProcessor exports on end()):
 *   - Both paths produce exactly 1 span (execute() does not recurse into other hooked methods).
 *
 * @see \OpenTelemetry\Contrib\Instrumentation\Magento2\Magento2Instrumentation
 */
final class ActionInterfaceTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<array-key, mixed> */
    private ArrayObject $storage;

    /** @var Forward */
    private Forward $forward;

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

        /** @psalm-suppress DeprecatedClass */
        $objectManager = new ObjectManager($this);

        $request = $this->getMockBuilder(\Magento\Framework\App\Request\Http::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var Http $response */
        $response = $objectManager->getObject(
            Http::class,
            [
                'cookieManager' => $this->createMock(CookieManagerInterface::class),
                'cookieMetadataFactory' => $this->getMockBuilder(CookieMetadataFactory::class)
                    ->disableOriginalConstructor()
                    ->getMock(),
                'context' => $this->getMockBuilder(Context::class)
                    ->disableOriginalConstructor()
                    ->getMock(),
            ]
        );

        /** @var Forward $forward */
        $forward = $objectManager->getObject(
            Forward::class,
            [
                'request' => $request,
                'response' => $response,
            ]
        );
        $this->forward = $forward;
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    /**
     * @test Happy path: execute() completes without exception.
     *
     * Calls Forward::execute() directly (bypassing dispatch) so only the
     * ActionInterface::execute hook fires.
     *
     * Asserts that:
     *   - exactly one span is exported
     *   - the span is named 'ActionInterface.execute'
     *   - code attributes (function name, file path, line number) are present and non-empty
     */
    public function test_execute_records_span_and_code_attributes(): void
    {
        $this->forward->execute();

        $this->assertCount(1, $this->storage);
        $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);

        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $this->assertSame('ActionInterface.execute', $span->getName());

        $attributes = $span->getAttributes()->toArray();
        $this->assertArrayHasKey(CodeAttributes::CODE_FUNCTION_NAME, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_FUNCTION_NAME]);
        $this->assertArrayHasKey(CodeAttributes::CODE_FILE_PATH, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_FILE_PATH]);
        $this->assertArrayHasKey(CodeAttributes::CODE_LINE_NUMBER, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_LINE_NUMBER]);
    }

    /**
     * @test Exception path: execute() throws; the post-hook records the exception on the span.
     *
     * An anonymous ActionInterface implementation is used so execute() throws a
     * RuntimeException without any framework side-effects.
     *
     * Asserts that:
     *   - the exception is rethrown (verified via expectException)
     *   - exactly one span is exported
     *   - the span is named 'ActionInterface.execute'
     *   - the span carries exactly one exception event with:
     *       exception.type    containing 'RuntimeException'
     *       exception.message = 'boom'
     *       exception.stacktrace (non-empty)
     *
     * The try/finally pattern ensures span assertions run even though the exception
     * propagates out of execute().
     */
    public function test_execute_records_exception_event(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $action = $this->createMock(ActionInterface::class);
        $action->expects($this->once())
            ->method('execute')
            ->willThrowException(new \RuntimeException('boom'));

        try {
            $action->execute();
        } finally {
            $this->assertCount(1, $this->storage);
            $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);

            /** @var ImmutableSpan $span */
            $span = $this->storage[0];
            $this->assertSame('ActionInterface.execute', $span->getName());

            $events = $span->getEvents();
            $this->assertCount(1, $events);
            $this->assertInstanceOf(Event::class, $events[0]);

            /** @var Event $event */
            $event = $events[0];
            $this->assertSame('exception', $event->getName());

            $eventAttributes = $event->getAttributes()->toArray();
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_TYPE, $eventAttributes);
            $this->assertStringContainsString('RuntimeException', (string) $eventAttributes[ExceptionAttributes::EXCEPTION_TYPE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_MESSAGE, $eventAttributes);
            $this->assertSame('boom', $eventAttributes[ExceptionAttributes::EXCEPTION_MESSAGE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_STACKTRACE, $eventAttributes);
            $this->assertNotEmpty($eventAttributes[ExceptionAttributes::EXCEPTION_STACKTRACE]);
        }
    }
}

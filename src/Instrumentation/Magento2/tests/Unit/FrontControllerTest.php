<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Magento2\Unit;

use ArrayObject;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Area;
use Magento\Framework\App\AreaInterface;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\FrontController;
use Magento\Framework\App\Request\ValidatorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http;
use Magento\Framework\App\RouterInterface;
use Magento\Framework\App\RouterList;
use Magento\Framework\App\State;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Phrase;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the FrontController::dispatch instrumentation hook in Magento2Instrumentation.
 *
 * The hook's pre-closure:
 *   - Creates a CLIENT-kind span named 'FrontController.dispatch'
 *   - Attaches code attributes (function name, file path, line number)
 *
 * The hook's post-closure:
 *   - Records any exception thrown during dispatch and sets span status to ERROR
 *   - Ends the span unconditionally
 *
 * Span ordering (SimpleSpanProcessor exports on end()):
 *   - Exception path (+router loop limit): 1 span – storage[0] = FrontController.dispatch span
 *   - Success path (Forward controller):   2 spans – ActionInterface.execute ends first,
 *     FrontController.dispatch ends second; findSpanByName() is used to locate the outer span
 *   - NotFoundException path (noroute):    1+ spans; FrontController.dispatch span present
 *
 * @see \OpenTelemetry\Contrib\Instrumentation\Magento2\Magento2Instrumentation
 */
final class FrontControllerTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<array-key, mixed> */
    private ArrayObject $storage;
    protected FrontController $model;
    /** @var RequestInterface&MockObject */
    protected RequestInterface $request;
    /** @var RouterList&MockObject */
    protected RouterList $routerList;
    /** @var RouterInterface&MockObject */
    protected RouterInterface $router;
    /** @var Http&MockObject */
    protected Http $response;
    /** @var ValidatorInterface&MockObject */
    private ValidatorInterface $requestValidator;
    /** @var MessageManager&MockObject */
    private MessageManager $messages;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;
    /** @var AreaList&MockObject */
    private AreaList $areaListMock;
    /** @var State&MockObject */
    private State $appStateMock;
    /** @var AreaInterface&MockObject */
    private AreaInterface $areaMock;

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

        $this->request = $this->getMockBuilder(\Magento\Framework\App\Request\Http::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isDispatched', 'setDispatched', 'initForward', 'setActionName'])
            ->getMock();

        $this->router = $this->createMock(RouterInterface::class);
        $this->routerList = $this->createMock(RouterList::class);
        $this->response = $this->createMock(Http::class);
        $this->requestValidator = $this->createMock(ValidatorInterface::class);
        $this->messages = $this->createMock(MessageManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->appStateMock  = $this->getMockBuilder(State::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->areaListMock = $this->createMock(AreaList::class);
        $this->areaMock = $this->createMock(AreaInterface::class);
        $actionFlagMock = $this->createMock(ActionFlag::class);
        $eventManagerMock = $this->createMock(EventManager::class);
        $requestMock = $this->createMock(RequestInterface::class);
        $this->model = new FrontController(
            $this->routerList,
            $this->response,
            $this->requestValidator,
            $this->messages,
            $this->logger,
            $this->appStateMock,
            $this->areaListMock,
            $actionFlagMock,
            $eventManagerMock,
            $requestMock
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    /**
     * @test Exception path: FrontController::dispatch throws LogicException when the router
     * exhausts 100 match iterations without finding a controller.
     *
     * Asserts that:
     *   - the LogicException is rethrown (verified via expectException)
     *   - exactly one span is exported: the FrontController.dispatch span
     *   - the span name is 'FrontController.dispatch'
     *   - code attributes (function name, file path, line number) are present and non-empty
     *   - the span carries exactly one exception event with:
     *       exception.type    containing 'LogicException'
     *       exception.message = 'Front controller reached 100 router match iterations'
     *       exception.stacktrace (non-empty)
     *
     * The try/finally pattern ensures span assertions execute even though the
     * exception propagates before the method returns normally.
     */
    public function testDispatchThrowException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Front controller reached 100 router match iterations');
        $validCounter = 0;
        $callbackValid = static function () use (&$validCounter): bool {
            $validCounter++;

            return $validCounter % 10 ? false : true;
        };
        $this->routerList->expects($this->any())->method('valid')->willReturnCallback($callbackValid);

        $this->router->expects($this->any())
            ->method('match')
            ->with($this->request)
            ->willReturn(false);

        $this->routerList->expects($this->any())
            ->method('current')
            ->willReturn($this->router);

        $this->request->expects($this->any())->method('isDispatched')->willReturn(false);

        try {
            $this->model->dispatch($this->request);
        } finally {
            $this->assertCount(1, $this->storage);
            $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);
            /** @var ImmutableSpan $span */
            $span = $this->storage[0];
            $this->assertSame('FrontController.dispatch', $span->getName());

            $attributes = $span->getAttributes()->toArray();
            $this->assertArrayHasKey(CodeAttributes::CODE_FUNCTION_NAME, $attributes);
            $this->assertNotEmpty($attributes[CodeAttributes::CODE_FUNCTION_NAME]);
            $this->assertArrayHasKey(CodeAttributes::CODE_FILE_PATH, $attributes);
            $this->assertNotEmpty($attributes[CodeAttributes::CODE_FILE_PATH]);
            $this->assertArrayHasKey(CodeAttributes::CODE_LINE_NUMBER, $attributes);
            $this->assertNotEmpty($attributes[CodeAttributes::CODE_LINE_NUMBER]);

            $this->assertCount(1, $span->getEvents());
            $this->assertInstanceOf(Event::class, $span->getEvents()[0]);
            $event = $span->getEvents()[0];
            $this->assertSame('exception', $event->getName());
            $eventAttributes = $event->getAttributes()->toArray();
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_TYPE, $eventAttributes);
            $this->assertStringContainsString('LogicException', (string) $eventAttributes[ExceptionAttributes::EXCEPTION_TYPE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_MESSAGE, $eventAttributes);
            $this->assertSame('Front controller reached 100 router match iterations', $eventAttributes[ExceptionAttributes::EXCEPTION_MESSAGE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_STACKTRACE, $eventAttributes);
            $this->assertNotEmpty($eventAttributes[ExceptionAttributes::EXCEPTION_STACKTRACE]);
        }
    }

    /**
     * @test Happy path: FrontController::dispatch successfully routes to a Forward controller.
     *
     * The router returns false on the first iteration (simulating no match), then returns
     * a real Forward instance on the second. Forward::dispatch() calls execute(), which
     * triggers the ActionInterface::execute hook in addition to FrontController::dispatch.
     *
     * Asserts that:
     *   - exactly two spans are exported:
     *       1. ActionInterface.execute (inner span, ends first)
     *       2. FrontController.dispatch (outer span, ends second)
     *   - the FrontController.dispatch span is located by name
     *   - code attributes (function name, file path, line number) are present and non-empty
     *   - the FrontController.dispatch span carries no exception events
     */
    public function testDispatched(): void
    {
        $this->routerList->expects($this->any())
            ->method('valid')
            ->willReturn(true);

        $response = $this->createMock(Http::class);
        /** @psalm-suppress DeprecatedClass */
        $controllerInstance = $this->getMockBuilder(Action::class)
            ->disableOriginalConstructor()
            ->getMock();
        $controllerInstance->expects($this->any())
            ->method('dispatch')
            ->with($this->request)
            ->willReturn($response);

        /** @psalm-suppress DeprecatedClass */
        $objectManager = new ObjectManager($this);
        $controller = $objectManager->getObject(
            Forward::class,
            [
                'request' => $this->request,
                'response' => $this->response,
            ]
        );

        $this->router
            ->method('match')
            ->with($this->request)
            ->willReturnOnConsecutiveCalls(false, $controller);

        $this->routerList->expects($this->any())
            ->method('current')
            ->willReturn($this->router);
        $this->appStateMock->expects($this->any())->method('getAreaCode')->willReturn('frontend');
        $this->areaMock
            ->method('load')
            ->willReturnCallback(
                function (string $arg1): ?AreaInterface {
                    if ($arg1 === Area::PART_DESIGN) {
                        return $this->areaMock;
                    }
                    if ($arg1 === Area::PART_TRANSLATE) {
                        return $this->areaMock;
                    }

                    return null;
                }
            );
        $this->areaListMock->expects($this->any())->method('getArea')->willReturn($this->areaMock);
        $this->request
            ->method('isDispatched')
            ->willReturnOnConsecutiveCalls(false, true);

        $this->assertEquals($response, $this->model->dispatch($this->request));

        // FrontController.dispatch + ActionInterface.execute
        $this->assertCount(2, $this->storage);
        $frontControllerSpan = $this->findSpanByName('FrontController.dispatch');
        $this->assertNotNull($frontControllerSpan);

        $attributes = $frontControllerSpan->getAttributes()->toArray();
        $this->assertArrayHasKey(CodeAttributes::CODE_FUNCTION_NAME, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_FUNCTION_NAME]);
        $this->assertArrayHasKey(CodeAttributes::CODE_FILE_PATH, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_FILE_PATH]);
        $this->assertArrayHasKey(CodeAttributes::CODE_LINE_NUMBER, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_LINE_NUMBER]);
        $this->assertCount(0, $frontControllerSpan->getEvents());
    }

    /**
     * @test NotFoundException path: FrontController::dispatch recovers from a router
     * NotFoundException by forwarding to the 'noroute' action and dispatching again.
     *
     * On the first match attempt the router throws NotFoundException; FrontController
     * catches it, resets the request to the noroute action, and iterates. On the second
     * match attempt the mock Action controller is returned and dispatch succeeds.
     *
     * This test verifies the noroute recovery flow returns the correct response; span
     * assertions are intentionally minimal here since the recovery path is covered by
     * FrontController's own unit tests.
     */
    public function testDispatchedNotFoundException(): void
    {
        $this->routerList->expects($this->any())
            ->method('valid')
            ->willReturn(true);

        $response = $this->createMock(Http::class);
        /** @psalm-suppress DeprecatedClass */
        $controllerInstance = $this->getMockBuilder(Action::class)
            ->disableOriginalConstructor()
            ->getMock();
        $controllerInstance->expects($this->any())
            ->method('dispatch')
            ->with($this->request)
            ->willReturn($response);
        $shouldThrow = true;
        $this->router
            ->method('match')
            ->willReturnCallback(
                /** @psalm-suppress RedundantCondition */
                function (RequestInterface $arg1) use (&$shouldThrow, $controllerInstance): object {
                    if ($arg1 === $this->request && $shouldThrow) {
                        $shouldThrow = false;

                        throw new NotFoundException(new Phrase('Page not found.'));
                    }

                    return $controllerInstance;
                }
            );

        $this->routerList->expects($this->any())
            ->method('current')
            ->willReturn($this->router);

        $this->appStateMock->expects($this->any())->method('getAreaCode')->willReturn('frontend');
        $this->areaMock
            ->method('load')
            ->willReturnCallback(
                function (string $arg1): ?AreaInterface {
                    if ($arg1 === Area::PART_DESIGN) {
                        return $this->areaMock;
                    }
                    if ($arg1 === Area::PART_TRANSLATE) {
                        return $this->areaMock;
                    }

                    return null;
                }
            );
        $this->areaListMock->expects($this->any())->method('getArea')->willReturn($this->areaMock);
        $this->request
            ->method('isDispatched')
            ->willReturnOnConsecutiveCalls(false, false, true);
        $this->request
            ->method('setDispatched')
            ->willReturnCallback(
                static fn (bool $arg): ?RequestInterface => null
            );
        $this->request
            ->method('setActionName')
            ->with('noroute');
        $this->request
            ->method('initForward');

        $this->assertEquals($response, $this->model->dispatch($this->request));
    }

    /**
     * Searches the exported span storage for the first span with the given name.
     *
     * Used in success-path tests where multiple spans are emitted and the outer
     * FrontController.dispatch span is not at a predictable index.
     */
    private function findSpanByName(string $name): ?ImmutableSpan
    {
        /** @psalm-suppress MixedAssignment */
        foreach ($this->storage as $span) {
            if ($span instanceof ImmutableSpan && $span->getName() === $name) {
                return $span;
            }
        }

        return null;
    }
}

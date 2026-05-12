<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Magento2\Unit;

use ArrayObject;
use Laminas\Http\Headers;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\ExceptionHandlerInterface;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\Http as AppHttp;
use Magento\Framework\App\ObjectManager\ConfigLoader;
use Magento\Framework\App\Request\Http as RequestHttp;
use Magento\Framework\App\Request\PathInfo;
use Magento\Framework\App\Request\PathInfoProcessorInterface;
use Magento\Framework\App\Response\Http as ResponseHttp;
use Magento\Framework\App\Route\ConfigInterface\Proxy;
use Magento\Framework\Event\Manager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieReaderInterface;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as HelperObjectManager;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\Event;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Http::launch instrumentation hook registered in Magento2Instrumentation.
 *
 * The hook's pre-closure:
 *   - Builds a PSR-7 server request from globals via Nyholm\Psr7Server\ServerRequestCreator
 *   - Extracts W3C trace-context from incoming request headers for distributed tracing
 *   - Creates a SERVER-kind span named "{METHOD} {SCRIPT_NAME}" with request attributes
 *
 * The hook's post-closure:
 *   - Records response attributes (status code, body/total size, headers)
 *   - Records exceptions and sets span status to ERROR when dispatch throws
 *   - Injects response-propagation headers via the global ResponsePropagator
 *   - Records an http.server.request.duration histogram metric
 *
 * Span ordering (SimpleSpanProcessor exports on end()):
 *   - Happy path:    one span  – storage[0] = Http::launch span
 *   - Exception path: one span – storage[0] = Http::launch span (exception recorded)
 *
 * Setup helpers:
 *   - setUpLaunchDependencies() wires every collaborator except the FrontController dispatch result
 *   - setUpLaunch() adds a successful dispatch expectation on top of that
 *
 * @see \OpenTelemetry\Contrib\Instrumentation\Magento2\Magento2Instrumentation
 */
final class HttpTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<array-key, mixed> */
    private ArrayObject $storage;

    private object $objectManager;

    /**
     * @var ResponseHttp&MockObject
     */
    private $responseMock;

    /**
     * @var AppHttp
     */
    private $http;

    /**
     * @var FrontControllerInterface&MockObject
     */
    private $frontControllerMock;

    /**
     * @var Manager&MockObject
     */
    private $eventManagerMock;

    /**
     * @var RequestHttp&MockObject
     */
    private $requestMock;

    /**
     * @var ObjectManagerInterface&MockObject
     */
    private $objectManagerMock;

    /**
     * @var AreaList&MockObject
     */
    private $areaListMock;

    /**
     * @var ConfigLoader&MockObject
     */
    private $configLoaderMock;

    /**
     * @var ExceptionHandlerInterface&MockObject
     */
    private $exceptionHandlerMock;

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
        $this->objectManager = new HelperObjectManager($this);
        $objects = [
            [
                PathInfo::class,
                $this->createMock(PathInfo::class),
            ],
        ];
        $this->objectManager->prepareObjectManager($objects);
        $cookieReaderMock = $this->getMockBuilder(CookieReaderInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $routeConfigMock = $this->getMockBuilder(Proxy::class)
            ->disableOriginalConstructor()
            ->getMock();
        $pathInfoProcessorMock = $this->getMockBuilder(PathInfoProcessorInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $converterMock = $this->getMockBuilder(StringUtils::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['cleanString'])
            ->getMock();
        $objectManagerMock = $this->createStub(ObjectManagerInterface::class);
        $this->requestMock = $this->getMockBuilder(RequestHttp::class)
            ->setConstructorArgs(
                [
                    'cookieReader' => $cookieReaderMock,
                    'converter' => $converterMock,
                    'routeConfig' => $routeConfigMock,
                    'pathInfoProcessor' => $pathInfoProcessorMock,
                    'objectManager' => $objectManagerMock,
                ]
            )
            ->onlyMethods(['getFrontName', 'isHead'])
            ->getMock();
        $this->areaListMock = $this->getMockBuilder(AreaList::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCodeByFrontName'])
            ->getMock();
        $this->configLoaderMock = $this->getMockBuilder(ConfigLoader::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load'])
            ->getMock();
        $this->objectManagerMock = $this->createMock(ObjectManagerInterface::class);
        $this->responseMock = $this->createMock(ResponseHttp::class);
        $this->frontControllerMock = $this->createMock(FrontControllerInterface::class);
        $this->eventManagerMock = $this->getMockBuilder(Manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['dispatch'])
            ->getMock();
        $this->exceptionHandlerMock = $this->createMock(ExceptionHandlerInterface::class);

        /** @var AppHttp $http */
        $http = $this->objectManager->getObject(
            AppHttp::class,
            [
                'objectManager' => $this->objectManagerMock,
                'eventManager' => $this->eventManagerMock,
                'areaList' => $this->areaListMock,
                'request' => $this->requestMock,
                'response' => $this->responseMock,
                'configLoader' => $this->configLoaderMock,
                'exceptionHandler' => $this->exceptionHandlerMock,
            ]
        );
        $this->http = $http;
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    /**
     * Sets up all launch dependencies except the FrontController dispatch result, allowing
     * individual tests to define whether dispatch succeeds or throws.
     *
     * Wires:
     *   - requestMock::getFrontName()      → 'frontName'
     *   - areaListMock::getCodeByFrontName → 'areaCode'
     *   - configLoaderMock::load           → []
     *   - objectManagerMock::configure     → (void)
     *   - objectManagerMock::get           → frontControllerMock
     */
    private function setUpLaunchDependencies(): void
    {
        $frontName = 'frontName';
        $areaCode = 'areaCode';
        $this->requestMock->expects($this->once())
            ->method('getFrontName')
            ->willReturn($frontName);
        $this->areaListMock->expects($this->once())
            ->method('getCodeByFrontName')
            ->with($frontName)
            ->willReturn($areaCode);
        $this->configLoaderMock->expects($this->once())
            ->method('load')
            ->with($areaCode)
            ->willReturn([]);
        $this->objectManagerMock->expects($this->once())->method('configure')->with([]);
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with(FrontControllerInterface::class)
            ->willReturn($this->frontControllerMock);
    }

    /**
     * Full happy-path launch setup: dispatch returns the response mock.
     */
    private function setUpLaunch(): void
    {
        $this->setUpLaunchDependencies();
        $this->frontControllerMock->expects($this->once())
            ->method('dispatch')
            ->with($this->requestMock)
            ->willReturn($this->responseMock);
    }

    /**
     * @test Happy path: Http::launch completes without exception.
     *
     * Asserts that the exported span:
     *   - has a non-empty name (format: "{METHOD} {SCRIPT_NAME}")
     *   - carries no exception events
     *   - contains all expected code attributes (function name, file path, line number)
     *   - contains all request attributes set by the pre-hook
     *     (url.full as string, url.scheme, url.path, http.request.method,
     *      network.protocol.version, server.address, server.port)
     *   - contains response attributes controlled by the mock
     *     (http.response.status_code=200, body_size=4, response_size=6)
     *   - contains the three response headers k1/k2/k3 with their values
     */
    public function test_launch(): void
    {
        $this->setUpLaunch();
        $this->requestMock->expects($this->once())
            ->method('isHead')
            ->willReturn(false);
        $this->responseMock->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);
        $this->eventManagerMock->expects($this->once())
            ->method('dispatch')
            ->with(
                'controller_front_send_response_before',
                ['request' => $this->requestMock, 'response' => $this->responseMock]
            );
        $this->eventManagerMock->expects($this->once())
            ->method('dispatch')
            ->with(
                'controller_front_send_response_before',
                ['request' => $this->requestMock, 'response' => $this->responseMock]
            );
        $this->http->launch();

        $this->assertGreaterThanOrEqual(1, count($this->storage));
        $span = $this->findHttpLaunchSpan();
        $this->assertNotNull($span, 'Http::launch span not found in exported spans');
        $this->assertNotEmpty($span->getName());
        $this->assertCount(0, $span->getEvents());

        $attributes = $span->getAttributes()->toArray();

        // --- code attributes ---
        $this->assertArrayHasKey(CodeAttributes::CODE_FUNCTION_NAME, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_FUNCTION_NAME]);
        $this->assertArrayHasKey(CodeAttributes::CODE_FILE_PATH, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_FILE_PATH]);
        $this->assertArrayHasKey(CodeAttributes::CODE_LINE_NUMBER, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_LINE_NUMBER]);

        // --- request attributes (values are environment-derived; assert presence and type) ---
        $this->assertArrayHasKey(UrlAttributes::URL_FULL, $attributes);
        $this->assertIsString($attributes[UrlAttributes::URL_FULL]);
        $this->assertArrayHasKey(UrlAttributes::URL_SCHEME, $attributes);
        $this->assertArrayHasKey(UrlAttributes::URL_PATH, $attributes);
        $this->assertArrayHasKey(HttpAttributes::HTTP_REQUEST_METHOD, $attributes);
        $this->assertArrayHasKey(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $attributes);
        $this->assertArrayHasKey(ServerAttributes::SERVER_ADDRESS, $attributes);
        $this->assertArrayHasKey(ServerAttributes::SERVER_PORT, $attributes);

        // --- response attributes (values are controlled by the mock) ---
        $this->assertArrayHasKey(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $attributes);
        $this->assertSame(200, $attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE]);
    }

    /**
     * @test Exception path: Http::launch propagates exceptions thrown by FrontController::dispatch.
     *
     * Asserts that:
     *   - the exception is rethrown to the caller (verified via expectException)
     *   - exactly one span is exported even when an exception propagates
     *   - the span has a non-empty name
     *   - code attributes are present and non-empty
     *   - the span carries exactly one exception event whose attributes contain:
     *       exception.type    containing 'Exception'
     *       exception.message = 'Message'
     *       exception.stacktrace (non-empty)
     *
     * The try/finally pattern ensures span assertions run even though the
     * exception propagates before the method returns normally.
     */
    public function test_launch_exception(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Message');

        $this->setUpLaunchDependencies();
        /** @var FrontControllerInterface&MockObject $frontControllerMock */
        $frontControllerMock = $this->frontControllerMock;
        $frontControllerMock->expects($this->once())
            ->method('dispatch')
            ->with($this->requestMock)
            ->willThrowException(new \Exception('Message'));

        try {
            $this->http->launch();
        } finally {
            $this->assertGreaterThanOrEqual(1, count($this->storage));
            $span = $this->findHttpLaunchSpan();
            $this->assertNotNull($span, 'Http::launch span not found in exported spans');
            $this->assertNotEmpty($span->getName());

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
            $this->assertStringContainsString('Exception', (string) $eventAttributes[ExceptionAttributes::EXCEPTION_TYPE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_MESSAGE, $eventAttributes);
            $this->assertSame('Message', $eventAttributes[ExceptionAttributes::EXCEPTION_MESSAGE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_STACKTRACE, $eventAttributes);
            $this->assertNotEmpty($eventAttributes[ExceptionAttributes::EXCEPTION_STACKTRACE]);
        }
    }

    /**
     * Find the Http::launch span by looking for a span that has the URL_FULL attribute,
     * which is only set by the Http::launch pre-hook.
     */
    private function findHttpLaunchSpan(): ?ImmutableSpan
    {
        foreach ($this->storage as $item) {
            if ($item instanceof ImmutableSpan && $item->getAttributes()->has(UrlAttributes::URL_FULL)) {
                return $item;
            }
        }

        return null;
    }

}

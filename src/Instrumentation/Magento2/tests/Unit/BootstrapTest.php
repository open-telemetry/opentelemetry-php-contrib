<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Magento2\Unit;

use ArrayObject;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\AppInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\ObjectManagerInterface;
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
 * Tests for the Bootstrap::terminate instrumentation hooks
 * in Magento2Instrumentation.
 *
 * Bootstrap::terminate hook (pre-only):
 *   - Creates a span named 'Bootstrap::terminate'
 *   - Attaches code attributes (function name, file path, line number)
 *   - If the first argument is a Throwable, records it as an exception event
 *     and sets span status to ERROR
 *   - Ends the span immediately inside the pre-closure (terminate is fire-and-forget,
 *     and exits the process, so there is no post-closure)
 *
 * Span ordering (SimpleSpanProcessor exports on end()):
 *   - terminate test: 1 span – storage[0] = Bootstrap::terminate span
 *
 * @see \OpenTelemetry\Contrib\Instrumentation\Magento2\Magento2Instrumentation
 */
final class BootstrapTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<array-key, mixed> */
    private ArrayObject $storage;
    /** @var AppInterface&MockObject */
    protected AppInterface $application;

    /** @var ObjectManagerFactory&MockObject */
    protected ObjectManagerFactory $objectManagerFactory;

    /** @var ObjectManagerInterface&MockObject */
    protected ObjectManagerInterface $objectManager;

    /** @var LoggerInterface&MockObject */
    protected LoggerInterface $logger;

    /** @var DirectoryList&MockObject */
    protected DirectoryList $dirs;

    /** @var ReadInterface&MockObject */
    protected ReadInterface $configDir;

    /** @var MaintenanceMode&MockObject */
    protected MaintenanceMode $maintenanceMode;

    /** @var DeploymentConfig&MockObject */
    protected DeploymentConfig $deploymentConfig;

    /** @var Bootstrap&MockObject */
    protected Bootstrap $bootstrapMock;

    /** @var RemoteAddress&MockObject */
    protected RemoteAddress $remoteAddress;

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

        $this->objectManagerFactory = $this->createMock(ObjectManagerFactory::class);
        $this->objectManager = $this->createMock(ObjectManagerInterface::class);
        $this->dirs = $this->createPartialMock(DirectoryList::class, ['getPath']);
        $this->maintenanceMode = $this->createPartialMock(MaintenanceMode::class, ['isOn']);
        $this->remoteAddress = $this->createMock(RemoteAddress::class);
        $filesystem = $this->createMock(Filesystem::class);

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);

        $mapObjectManager = [
            [DirectoryList::class, $this->dirs],
            [MaintenanceMode::class, $this->maintenanceMode],
            [RemoteAddress::class, $this->remoteAddress],
            [Filesystem::class, $filesystem],
            [DeploymentConfig::class, $this->deploymentConfig],
            [LoggerInterface::class, $this->logger],
        ];

        $this->objectManager->expects($this->any())->method('get')
            ->willReturnMap($mapObjectManager);

        $this->configDir = $this->createMock(ReadInterface::class);

        $filesystem->expects($this->any())->method('getDirectoryRead')
            ->willReturn($this->configDir);

        $this->application = $this->createMock(AppInterface::class);

        $this->objectManager->expects($this->any())->method('create')
            ->willReturn($this->application);

        $this->objectManagerFactory->expects($this->any())->method('create')
            ->willReturn($this->objectManager);

        $this->bootstrapMock = $this->getMockBuilder(Bootstrap::class)
            ->onlyMethods(['assertMaintenance', 'assertInstalled', 'getIsExpected', 'isInstalled', 'terminate'])
            ->setConstructorArgs([$this->objectManagerFactory, '', ['value1', 'value2']])
            ->getMock();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    /**
     * @test run() path: Bootstrap::run emits a span that records an exception when
     * assertMaintenance() throws (application is in maintenance mode).
     *
     * The bootstrapMock stubs terminate() so the real exit(1) is never called.
     * application::catchException() returns false, causing the exception to propagate.
     *
     * Asserts that:
     *   - exactly one span is exported (the Bootstrap.run span)
     *   - the span carries exactly one exception event with:
     *       exception.type    = 'Exception'
     *       exception.message = 'Message'
     *       exception.stacktrace (non-empty)
     */
    public function test_run_with_maintenance_errors(): void
    {
        $expectedException = new \Exception('Message');
        $this->bootstrapMock->expects($this->once())->method('assertMaintenance')
            ->willThrowException($expectedException);
        $this->bootstrapMock->expects($this->once())->method('terminate')->with($expectedException);
        $this->application->expects($this->once())->method('catchException')->willReturn(false);
        $this->runAndRestoreErrorHandler($this->bootstrapMock, $this->application);

        $this->assertCount(1, $this->storage);
        $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);
        $span = $this->storage[0];
        $this->assertCount(1, $span->getEvents());
        $this->assertInstanceOf(Event::class, $span->getEvents()[0]);
        $event = $span->getEvents()[0];
        $this->assertEquals('exception', $event->getName());
        $eventAttributes = $event->getAttributes()->toArray();
        $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_TYPE, $eventAttributes);
        $this->assertEquals('Exception', $eventAttributes[ExceptionAttributes::EXCEPTION_TYPE]);
        $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_MESSAGE, $eventAttributes);
        $this->assertEquals('Message', $eventAttributes[ExceptionAttributes::EXCEPTION_MESSAGE]);
        $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_STACKTRACE, $eventAttributes);
        $this->assertNotEmpty($eventAttributes[ExceptionAttributes::EXCEPTION_STACKTRACE]);
    }

    /**
     * @test terminate() path: Bootstrap::terminate emits a span and records the Throwable
     * passed as its first argument.
     *
     * terminate() is protected; invokeProtectedTerminate() calls it via reflection so
     * the OTel pre-hook fires without executing the real method body (exit(1)).
     *
     * Asserts that:
     *   - exactly one span is exported
     *   - the span name is 'Bootstrap::terminate'
     *   - code attributes (function name, file path, line number) are present and non-empty
     *   - the span carries exactly one exception event with:
     *       exception.type    containing 'RuntimeException'
     *       exception.message = 'Terminate failed'
     *       exception.stacktrace (non-empty)
     */
    public function test_terminate_records_span_and_exception_attributes(): void
    {
        $throwable = new \RuntimeException('Terminate failed');

        $this->invokeProtectedTerminate($this->bootstrapMock, $throwable);

        $this->assertCount(1, $this->storage);
        $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);
        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $this->assertSame('Bootstrap::terminate', $span->getName());

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
        $this->assertStringContainsString('RuntimeException', (string) $eventAttributes[ExceptionAttributes::EXCEPTION_TYPE]);
        $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_MESSAGE, $eventAttributes);
        $this->assertSame('Terminate failed', $eventAttributes[ExceptionAttributes::EXCEPTION_MESSAGE]);
        $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_STACKTRACE, $eventAttributes);
        $this->assertNotEmpty($eventAttributes[ExceptionAttributes::EXCEPTION_STACKTRACE]);
    }

    /**
     * Invokes the protected Bootstrap::terminate() via reflection so the OTel hook fires
     * without executing the real body (which calls exit(1)).
     */
    private function invokeProtectedTerminate(Bootstrap $bootstrap, \Throwable $throwable): void
    {
        $method = new \ReflectionMethod($bootstrap, 'terminate');
        $method->invoke($bootstrap, $throwable);
    }

    /**
     * Runs Bootstrap::run() and restores the error handler set by Bootstrap::initErrorHandler()
     * regardless of outcome, preventing handler leakage between tests.
     */
    private function runAndRestoreErrorHandler(Bootstrap $bootstrap, AppInterface $application): void
    {
        try {
            $bootstrap->run($application);
        } finally {
            restore_error_handler();
        }
    }
}

<?php

namespace OpenTelemetry\Tests\Instrumentation\Slim\Integration;

use ArrayObject;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use OpenTelemetry\API\Common\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\InvocationStrategyInterface;

class SlimInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;

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
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_request_handler_handle(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
        $request = new ServerRequest(
            'GET',
            new Uri('http://example.com/foo'),
            [],
            'body',
            '1.1',
        );
        $this->assertCount(0, $this->storage);
        $handler->handle($request);
        $this->assertCount(1, $this->storage);
    }

    public function testInvocationStrategy(): void
    {
        $strategy = $this->createMockStrategy();
        $this->assertCount(0, $this->storage);
        $strategy->__invoke(
            function(): ResponseInterface {
                return new Response();
            },
            $this->createMock(ServerRequestInterface::class),
            $this->createMock(ResponseInterface::class),
            []
        );
        $this->assertCount(1, $this->storage);
    }

    public function createMockStrategy(): InvocationStrategyInterface
    {
        return new class implements InvocationStrategyInterface {
            public function __invoke(callable $callable, ServerRequestInterface $request, ResponseInterface $response, array $routeArguments): ResponseInterface
            {
                return $response;
            }
        };
    }
}

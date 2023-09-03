<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Laravel\Integration;

use ArrayObject;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\Tests\Instrumentation\Laravel\TestCase;

class ConsoleInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;

    public function setUp(): void
    {
        parent::setUp();

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
        parent::tearDown();
    }

    public function test_command_tracing(): void
    {
        $this->assertCount(0, $this->storage);

        /** @var Kernel $kernel */
        $kernel = $this->app[Kernel::class];
        $exitCode = $kernel->handle(
            new \Symfony\Component\Console\Input\ArrayInput(['optimize:clear']),
            new \Symfony\Component\Console\Output\NullOutput(),
        );

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertCount(8, $this->storage);

        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertSame('Artisan handler', $span->getName());
        $span = $this->storage->offsetGet(1);
        $this->assertSame('Command optimize:clear', $span->getName());
    }
}

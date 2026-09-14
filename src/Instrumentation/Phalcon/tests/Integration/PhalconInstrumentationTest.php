<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Phalcon\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\Tests\Instrumentation\Phalcon\Integration\Fixtures\OverridingDispatcher;
use Phalcon\Cli\Console;
use Phalcon\Di\Di;
use Phalcon\Di\FactoryDefault;
use Phalcon\Di\FactoryDefault\Cli as CliFactoryDefault;
use Phalcon\Mvc\Application;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\Micro;
use Phalcon\Mvc\Router;
use PHPUnit\Framework\TestCase;

final class PhalconInstrumentationTest extends TestCase
{
    private const NAMESPACE = 'OpenTelemetry\\Tests\\Instrumentation\\Phalcon\\Integration\\Fixtures';

    private ScopeInterface $scope;
    private ArrayObject $storage;

    protected function setUp(): void
    {
        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(new InMemoryExporter($this->storage)),
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->activate();

        Di::reset();
    }

    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    private function factoryDefault(): FactoryDefault
    {
        $di = new FactoryDefault();
        /** @var Dispatcher $dispatcher */
        $dispatcher = $di->getShared('dispatcher');
        $dispatcher->setDefaultNamespace(self::NAMESPACE);

        return $di;
    }

    public function test_application_handle_produces_one_root_span_and_one_action_span(): void
    {
        $di = $this->factoryDefault();
        /** @var Dispatcher $dispatcher */
        $dispatcher = $di->getShared('dispatcher');
        $dispatcher->setControllerName('index');
        $dispatcher->setActionName('index');

        $app = new Application($di);
        $app->useImplicitView(false);
        $app->handle('/');

        $this->assertCount(2, $this->storage);

        $root = $this->storage->offsetGet(1);
        $action = $this->storage->offsetGet(0);

        $this->assertSame(SpanKind::KIND_SERVER, $root->getKind());
        $this->assertSame(SpanKind::KIND_INTERNAL, $action->getKind());
        $this->assertSame('index/index', $action->getName());
    }

    /**
     * Regression test for the coverage gap that drove this whole design: an
     * app dispatching directly (e.g. an API front controller building a
     * Dispatcher and calling ->dispatch() without ever constructing an
     * Application) must still get a root span. Application::handle() alone
     * never fires for this style of entry point.
     */
    public function test_bare_dispatch_without_application_produces_root_span(): void
    {
        $di = $this->factoryDefault();
        /** @var Dispatcher $dispatcher */
        $dispatcher = $di->getShared('dispatcher');
        $dispatcher->setControllerName('index');
        $dispatcher->setActionName('index');

        $dispatcher->dispatch();

        $this->assertCount(2, $this->storage);
        $root = $this->storage->offsetGet(1);
        $this->assertSame(SpanKind::KIND_SERVER, $root->getKind());
    }

    /**
     * forward() doesn't re-enter dispatch() (it resets a flag that makes
     * dispatch()'s own internal loop run callActionMethod() again for the
     * new action) — this exercises the callActionMethod dedup logic across
     * two actions in the same dispatch() call, not the root-span guard.
     */
    public function test_forward_does_not_produce_a_second_root_span(): void
    {
        $di = $this->factoryDefault();
        /** @var Dispatcher $dispatcher */
        $dispatcher = $di->getShared('dispatcher');
        $dispatcher->setControllerName('index');
        $dispatcher->setActionName('forward');

        $dispatcher->dispatch();

        $roots = array_filter(
            iterator_to_array($this->storage),
            static fn ($span) => $span->getKind() === SpanKind::KIND_SERVER,
        );
        $this->assertCount(1, $roots, 'exactly one root span despite the forward() re-dispatch');

        $actionNames = array_map(
            static fn ($span) => $span->getName(),
            array_filter(
                iterator_to_array($this->storage),
                static fn ($span) => $span->getKind() === SpanKind::KIND_INTERNAL,
            ),
        );
        $this->assertContains('index/forward', $actionNames);
        $this->assertContains('index/index', $actionNames, 'the forwarded action gets its own span too');
    }

    public function test_named_route_renames_root_span(): void
    {
        $di = $this->factoryDefault();

        $router = new Router(false);
        $router->add('/', ['controller' => 'index', 'action' => 'index'])->setName('home');
        $di->setShared('router', $router);

        $app = new Application($di);
        $app->useImplicitView(false);
        $app->handle('/');

        $root = $this->storage->offsetGet($this->storage->count() - 1);
        $this->assertSame('GET home', $root->getName());
    }

    public function test_exception_in_action_is_recorded_on_root_span(): void
    {
        $di = $this->factoryDefault();
        /** @var Dispatcher $dispatcher */
        $dispatcher = $di->getShared('dispatcher');
        $dispatcher->setControllerName('index');
        $dispatcher->setActionName('fail');

        try {
            $dispatcher->dispatch();
        } catch (\Throwable) {
            // Dispatcher without an eventsManager exception handler rethrows; expected here.
        }

        $root = $this->storage->offsetGet($this->storage->count() - 1);
        $this->assertNotEmpty($root->getEvents(), 'exception recorded as a span event');
    }

    /** Micro::handle() never touches a dispatcher at all — this is its own, otherwise-uncovered entry point. */
    public function test_micro_handle_produces_root_span(): void
    {
        $di = new FactoryDefault();

        $app = new Micro($di);
        $app->get('/', static fn () => 'ok');
        $app->handle('/');

        $this->assertCount(1, $this->storage);
        $root = $this->storage->offsetGet(0);
        $this->assertSame(SpanKind::KIND_SERVER, $root->getKind());
    }

    /**
     * Cli\Console::handle() must produce an INTERNAL root span, not a fake
     * HTTP one — Cli\Dispatcher extends the same AbstractDispatcher as the
     * MVC one, so this is the regression test for that misclassification.
     * (Root and action span end up with the same name here — both resolve
     * to task/action 'main/main' — so kind/attributes are checked across
     * every span from this run rather than trying to single out "the root".)
     */
    public function test_console_handle_produces_internal_root_span(): void
    {
        $di = new CliFactoryDefault();

        $console = new Console($di);
        $console->handle(['task' => 'main', 'action' => 'main']);

        $this->assertCount(2, $this->storage, 'one root span, one action span');

        foreach ($this->storage as $span) {
            $this->assertSame(SpanKind::KIND_INTERNAL, $span->getKind(), 'no CLI span may be SERVER-kind');
            $this->assertArrayNotHasKey(
                'http.request.method',
                $span->getAttributes()->toArray(),
                'no CLI span may carry HTTP attributes',
            );
        }
    }

    /**
     * A Dispatcher subclass overriding callActionMethod() and calling
     * parent:: internally must still produce exactly one action span, not
     * two — see PhalconInstrumentation's class docblock for why the naive
     * "one hook() registration = one firing" assumption doesn't hold here.
     */
    public function test_overriding_dispatcher_produces_exactly_one_action_span(): void
    {
        $di = $this->factoryDefault();
        $di->setShared('dispatcher', OverridingDispatcher::class);
        /** @var OverridingDispatcher $dispatcher */
        $dispatcher = $di->getShared('dispatcher');
        $dispatcher->setDefaultNamespace(self::NAMESPACE);
        $dispatcher->setControllerName('index');
        $dispatcher->setActionName('index');

        $dispatcher->dispatch();

        $actionSpans = array_filter(
            iterator_to_array($this->storage),
            static fn ($span) => $span->getKind() === SpanKind::KIND_INTERNAL,
        );
        $this->assertCount(1, $actionSpans, 'override + parent:: call must collapse to one span, not two');
    }

    /** Confirmed against Phalcon\Http\Response source: getStatusCode() returns null unless setStatusCode() was called. */
    public function test_response_without_explicit_status_code_defaults_to_200(): void
    {
        $di = $this->factoryDefault();
        /** @var Dispatcher $dispatcher */
        $dispatcher = $di->getShared('dispatcher');
        $dispatcher->setControllerName('index');
        $dispatcher->setActionName('index');

        $dispatcher->dispatch();

        $root = $this->storage->offsetGet($this->storage->count() - 1);
        $this->assertSame(200, $root->getAttributes()->toArray()['http.response.status_code'] ?? null);
    }

    /**
     * Confirmed against Phalcon\Mvc\Router source: Di\FactoryDefault's
     * default two routes are defined directly as raw PCRE patterns
     * (`#^/...#u`). Without the '#'-prefix guard, an app that never defines
     * its own routes would get a span literally named after that regex.
     */
    public function test_default_router_regex_route_falls_back_to_controller_action_name(): void
    {
        // FactoryDefault's router already has the default regex routes attached
        // (new Router(true)); Application::handle() resolves controller/action
        // from whichever one matches '/index/index' on its own.
        $di = $this->factoryDefault();

        $app = new Application($di);
        $app->useImplicitView(false);
        $app->handle('/index/index');

        $root = $this->storage->offsetGet($this->storage->count() - 1);
        $this->assertStringStartsNotWith('GET #', $root->getName());
        $this->assertSame('GET /index/index', $root->getName());
    }
}

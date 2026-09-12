<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Phalcon;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use Phalcon\Cli\Console;
use Phalcon\Di\DiInterface;
use Phalcon\Dispatcher\AbstractDispatcher;
use Phalcon\Http\RequestInterface;
use Phalcon\Http\ResponseInterface;
use Phalcon\Mvc\Application;
use Phalcon\Mvc\Micro;
use Phalcon\Mvc\Router\RouteInterface;
use Phalcon\Mvc\RouterInterface;
use ReflectionMethod;
use Throwable;

/**
 * OpenTelemetry auto-instrumentation for the Phalcon PHP framework.
 *
 * Requires PHP >= 8.2: `hook()` observes internal/extension functions (which
 * is what every method on compiled Phalcon classes is) via the Zend Observer
 * API's internal-function support, added in PHP 8.2. On 8.1 these hooks
 * register but never fire, silently.
 *
 * ## Root span sources
 *
 * A single request can reach Phalcon through several different entry
 * points, and no one of them covers every app:
 *
 * - `Phalcon\Mvc\Application::handle()` — full MVC apps.
 * - `Phalcon\Mvc\Micro::handle()` — Micro apps, which route straight to a
 *   handler callable and never touch a dispatcher at all.
 * - `Phalcon\Cli\Console::handle()` — CLI tasks.
 * - `Phalcon\Dispatcher\AbstractDispatcher::dispatch()`, as a fallback root
 *   only when none of the above already started one — apps that build and
 *   dispatch a `Phalcon\Mvc\Dispatcher` (or a subclass) directly, without a
 *   surrounding `Application`.
 *
 * All four share one depth counter (`$rootDepth`): whichever call takes it
 * from 0 to 1 owns the root span; whichever call brings it back to 0 ends
 * it. This also makes a dispatcher re-entering `dispatch()` mid-request
 * (e.g. an app resolving a nested resource from within a controller action
 * by calling `$dispatcher->dispatch()` again — not Phalcon's own
 * `forward()`, which does not re-enter `dispatch()` at all; it just resets
 * a flag that makes `dispatch()`'s own internal loop run its
 * `callActionMethod()` step again) a no-op at the root level —
 * `Application::handle()` calling `dispatch()` internally, and a controller
 * action calling `dispatch()` again, are both just deeper, already-rooted
 * calls.
 *
 * `AbstractDispatcher` is also the shared base of `Phalcon\Cli\Dispatcher`
 * (CLI), not just `Phalcon\Mvc\Dispatcher` (HTTP) — the fallback root branch
 * checks `method_exists($target, 'getControllerName')` (present only on the
 * MVC dispatcher) to decide whether to treat the request as HTTP (SERVER
 * span, HTTP semantic attributes) or not (INTERNAL span, no HTTP
 * attributes), rather than assuming every dispatcher call is a web request.
 *
 * ## Child spans: callActionMethod()
 *
 * One `hook()` registration on the base `AbstractDispatcher::callActionMethod`
 * — the method's true declaring class, which every dispatcher subclass
 * inherits it from — covers every dispatcher subclass via zend_observer's
 * own ancestor-chain lookup; a second registration directly on an app's own
 * subclass is unnecessary.
 *
 * A subclass that overrides `callActionMethod()` and calls
 * `parent::callActionMethod()` internally (a common pattern for apps adding
 * behaviour around action dispatch) causes the hook to fire twice for one
 * real action: once with the executing frame's declaring scope equal to the
 * subclass's own override, once more with it equal to `AbstractDispatcher`
 * itself (the `parent::` call) — stock Phalcon 5.x does not do this
 * (`Mvc\Dispatcher` doesn't override `callActionMethod`;
 * `Cli\Dispatcher`'s override doesn't call `parent::`), so this only
 * matters for apps with their own Dispatcher subclass doing so, but it's
 * cheap to handle unconditionally. Both `hook()`'s pre and post callbacks
 * receive `$class` (the executing frame's declaring scope) as a trailing
 * parameter if declared — comparing it against the target's actual
 * polymorphic resolution for `callActionMethod` — `(new
 * ReflectionMethod($target, 'callActionMethod'))->getDeclaringClass()->getName()`,
 * cached per runtime class — identifies the one real, outermost-for-this-call
 * firing unambiguously, including for deeper override chains and for a
 * genuinely nested action invocation (which lands at its own,
 * separately-resolved firing rather than being conflated with the pair
 * above).
 *
 * ## Route naming
 *
 * The root span is renamed once its route is known — the router's matched
 * route name when the app defines one, its raw pattern otherwise, falling
 * back to `{controller}/{action}` (`{task}/{action}` for CLI) only when
 * there is no router/route to ask. Controller/action (or task/action) are
 * captured once per root span, at the first real `callActionMethod` firing
 * — the router does not always resolve them onto the dispatcher before
 * `dispatch()` is entered (an unmatched route falling back to a default
 * handler resolves them lazily, inside `dispatch()` itself).
 */
final class PhalconInstrumentation
{
    public const NAME = 'phalcon';

    /** Shared entry guard across every root-span source; see class docblock. */
    private static int $rootDepth = 0;

    private static bool $rootNameCaptured = false;
    private static string $rootController = '';
    private static string $rootAction = '';

    /** @var array<class-string, class-string> callActionMethod's true polymorphic declaring class per dispatcher class, cached. */
    private static array $declaringClassCache = [];

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.phalcon',
            null,
            'https://opentelemetry.io/schemas/1.38.0',
        );

        self::hookApplicationHandle($instrumentation);
        self::hookMicroHandle($instrumentation);
        self::hookConsoleHandle($instrumentation);
        self::hookDispatch($instrumentation);
        self::hookCallActionMethod($instrumentation);
        self::hookHandleException();
    }

    private static function hookApplicationHandle(CachedInstrumentation $instrumentation): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            Application::class,
            'handle',
            pre: static function (
                Application $app,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): void {
                if (!self::beginRoot()) {
                    return;
                }

                self::startHttpRootSpan($instrumentation, $app, $class, $function, $filename, $lineno);
            },
            post: static function (Application $app, array $params, mixed $returnValue, ?Throwable $exception): void {
                self::finishRootSpan(true, $app, $returnValue instanceof ResponseInterface ? $returnValue : null, $exception);
            },
        );
    }

    private static function hookMicroHandle(CachedInstrumentation $instrumentation): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            Micro::class,
            'handle',
            pre: static function (
                Micro $app,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): void {
                if (!self::beginRoot()) {
                    return;
                }

                self::startHttpRootSpan($instrumentation, $app, $class, $function, $filename, $lineno);
            },
            post: static function (Micro $app, array $params, mixed $returnValue, ?Throwable $exception): void {
                self::finishRootSpan(true, $app, $returnValue instanceof ResponseInterface ? $returnValue : null, $exception);
            },
        );
    }

    private static function hookConsoleHandle(CachedInstrumentation $instrumentation): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            Console::class,
            'handle',
            pre: static function (
                Console $console,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): void {
                if (!self::beginRoot()) {
                    return;
                }

                self::startInternalRootSpan($instrumentation, 'console', $class, $function, $filename, $lineno);
            },
            post: static function (Console $console, array $params, mixed $returnValue, ?Throwable $exception): void {
                self::finishRootSpan(false, $console, null, $exception);
            },
        );
    }

    /**
     * Fallback root: only takes effect when an app dispatches directly,
     * without Application/Micro/Console already having started a root span
     * (beginRoot() returns false otherwise). Covers HTTP (Mvc\Dispatcher)
     * and non-HTTP (e.g. a hand-built Cli\Dispatcher) cases distinctly.
     */
    private static function hookDispatch(CachedInstrumentation $instrumentation): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            AbstractDispatcher::class,
            'dispatch',
            pre: static function (
                AbstractDispatcher $target,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): void {
                if (!self::beginRoot()) {
                    return;
                }

                if (self::isHttpDispatcher($target)) {
                    self::startHttpRootSpan($instrumentation, $target, $class, $function, $filename, $lineno);

                    return;
                }

                self::startInternalRootSpan($instrumentation, 'console', $class, $function, $filename, $lineno);
            },
            post: static function (AbstractDispatcher $target, array $params, mixed $returnValue, ?Throwable $exception): void {
                self::finishRootSpan(self::isHttpDispatcher($target), $target, null, $exception);
            },
        );
    }

    private static function hookCallActionMethod(CachedInstrumentation $instrumentation): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            AbstractDispatcher::class,
            'callActionMethod',
            pre: static function (
                AbstractDispatcher $target,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): void {
                if (!self::isRealActionInvocation($target, $class)) {
                    return;
                }

                if (!self::$rootNameCaptured) {
                    [self::$rootController, self::$rootAction] = self::dispatcherName($target);
                    self::$rootNameCaptured = true;
                }

                self::startActionSpan($instrumentation, $target, $class, $function, $filename, $lineno);
            },
            // $class here is the same trailing parameter pre() receives — hook()
            // passes it to post callbacks too, it just needs declaring.
            post: static function (AbstractDispatcher $target, array $params, mixed $returnValue, ?Throwable $exception, string $class): void {
                if (!self::isRealActionInvocation($target, $class)) {
                    return;
                }

                self::endActionSpan($exception);
            },
        );
    }

    /**
     * Exceptions Phalcon's own dispatch loop hands to `dispatch:beforeException`
     * listeners (e.g. an app forwarding to an error controller) never reach
     * dispatch()'s own post hook as its `$exception` argument — dispatch()
     * returns normally once the listener handles it. Recording on whatever
     * span is current at the point of the exception, rather than relying on
     * a post-hook parameter, catches these too.
     */
    private static function hookHandleException(): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            AbstractDispatcher::class,
            'handleException',
            pre: static function (AbstractDispatcher $target, array $params): void {
                $throwable = $params[0] ?? null;
                if ($throwable instanceof Throwable) {
                    Span::getCurrent()->recordException($throwable);
                }
            },
            post: null,
        );
    }

    private static function beginRoot(): bool
    {
        if (self::$rootDepth++ > 0) {
            return false;
        }

        self::$rootController = '';
        self::$rootAction = '';
        self::$rootNameCaptured = false;

        return true;
    }

    private static function endRoot(): bool
    {
        return --self::$rootDepth === 0;
    }

    private static function startHttpRootSpan(
        CachedInstrumentation $instrumentation,
        object $target,
        string $class,
        string $function,
        ?string $filename,
        ?int $lineno,
    ): void {
        $carrier = self::carrierFromServerGlobals();
        $parentContext = $carrier === [] ? Context::getCurrent() : Globals::propagator()->extract($carrier);

        [$method, $path, $scheme, $host, $port, $userAgent] = self::requestAttributes($target);

        $span = $instrumentation->tracer()
            ->spanBuilder($method)
            ->setParent($parentContext)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
            ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
            ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $method)
            ->setAttribute(UrlAttributes::URL_PATH, $path)
            ->setAttribute(UrlAttributes::URL_SCHEME, $scheme)
            ->setAttribute(ServerAttributes::SERVER_ADDRESS, $host)
            ->setAttribute(ServerAttributes::SERVER_PORT, $port)
            ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $userAgent)
            ->startSpan();

        Context::storage()->attach($span->storeInContext($parentContext));
    }

    /**
     * Prefers Phalcon's own `Request` service (respects its trusted-proxy
     * handling for scheme/host, matching how sibling packages derive these
     * from their framework's own request object) over raw `$_SERVER`, which
     * is used only when the service isn't resolvable.
     *
     * @return array{0: string, 1: string, 2: string, 3: ?string, 4: ?int, 5: ?string} method, path, scheme, host, port, user agent
     */
    private static function requestAttributes(object $target): array
    {
        $di = self::resolveDi($target);
        if ($di !== null && $di->has('request')) {
            try {
                $request = $di->getShared('request');
                if ($request instanceof RequestInterface) {
                    return [
                        $request->getMethod(),
                        $request->getURI(true),
                        $request->getScheme(),
                        $request->getHttpHost(),
                        $request->getPort(),
                        $request->getUserAgent(),
                    ];
                }
            } catch (Throwable) {
                // Fall through to $_SERVER below.
            }
        }

        $method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $requestUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $port = isset($_SERVER['SERVER_PORT']) ? (int) $_SERVER['SERVER_PORT'] : null;

        return [
            $method,
            explode('?', $requestUri, 2)[0],
            $scheme,
            $_SERVER['SERVER_NAME'] ?? null,
            $port,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];
    }

    private static function startInternalRootSpan(
        CachedInstrumentation $instrumentation,
        string $name,
        string $class,
        string $function,
        ?string $filename,
        ?int $lineno,
    ): void {
        $span = $instrumentation->tracer()
            ->spanBuilder($name)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
            ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
            ->startSpan();

        Context::storage()->attach($span->storeInContext(Context::getCurrent()));
    }

    /**
     * @param object|null $target the Application/Micro/Console/Dispatcher instance that just
     *     finished handling, used to resolve DI/response lazily — only once endRoot() confirms
     *     this call actually owns the root span, not eagerly on every nested/non-owning call.
     * @param ResponseInterface|null $preferredResponse use this instead of resolving one from
     *     $target's DI, when the caller already has the real return value in hand.
     */
    private static function finishRootSpan(bool $isHttp, ?object $target, ?ResponseInterface $preferredResponse, ?Throwable $exception): void
    {
        if (!self::endRoot()) {
            return;
        }

        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }

        $scope->detach();
        $span = Span::fromContext($scope->context());

        $di = ($isHttp && $target !== null) ? self::resolveDi($target) : null;
        self::annotateWithRoute($span, $isHttp, $di, self::$rootController, self::$rootAction);

        if ($isHttp) {
            $response = $preferredResponse ?? ($target !== null ? self::resolveResponse($target) : null);
            if ($response instanceof ResponseInterface) {
                // getStatusCode() returns null unless setStatusCode() was explicitly
                // called — Phalcon defaults to sending 200 either way, so a null
                // reading here means 200, not "unknown".
                $statusCode = $response->getStatusCode() ?? 200;
                $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);
                if ($statusCode >= 500) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                Globals::responsePropagator()->inject($response, ResponsePropagationSetter::instance(), $scope->context());
            }
        }

        if ($exception !== null) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }

    private static function startActionSpan(
        CachedInstrumentation $instrumentation,
        AbstractDispatcher $target,
        string $class,
        string $function,
        ?string $filename,
        ?int $lineno,
    ): void {
        [$name, $action] = self::dispatcherName($target);
        $isHttp = self::isHttpDispatcher($target);

        $spanName = ($name !== '' && $action !== '') ? sprintf('%s/%s', $name, $action) : 'callActionMethod';

        $builder = $instrumentation->tracer()
            ->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
            ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);

        if ($name !== '') {
            $builder->setAttribute($isHttp ? 'phalcon.controller' : 'phalcon.task', $name);
        }
        if ($action !== '') {
            $builder->setAttribute('phalcon.action', $action);
        }

        // No explicit setParent(): SpanBuilder::startSpan() falls back to
        // Context::storage()->current() (the active root span, or a parent
        // action span for a nested/re-entrant dispatch) when none is set.
        $span = $builder->startSpan();
        Context::storage()->attach($span->storeInContext(Context::getCurrent()));
    }

    private static function endActionSpan(?Throwable $exception): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }

        $scope->detach();
        $span = Span::fromContext($scope->context());

        if ($exception !== null) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }

    private static function annotateWithRoute(SpanInterface $span, bool $isHttp, ?DiInterface $di, string $name, string $action): void
    {
        try {
            if ($isHttp) {
                $routeLabel = $di !== null ? self::matchedRouteLabel($di) : null;
                $method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';

                if ($routeLabel !== null) {
                    $span->setAttribute(HttpAttributes::HTTP_ROUTE, $routeLabel);
                    $span->updateName(sprintf('%s %s', $method, $routeLabel));

                    return;
                }

                if ($name !== '') {
                    $span->setAttribute('phalcon.controller', $name);
                }
                if ($action !== '') {
                    $span->setAttribute('phalcon.action', $action);
                }
                if ($name !== '' && $action !== '') {
                    $span->updateName(sprintf('%s /%s/%s', $method, $name, $action));
                }

                return;
            }

            if ($name !== '') {
                $span->setAttribute('phalcon.task', $name);
            }
            if ($action !== '') {
                $span->setAttribute('phalcon.action', $action);
            }
            if ($name !== '' && $action !== '') {
                $span->updateName(sprintf('%s/%s', $name, $action));
            }
        } catch (Throwable) {
            // Route resolution is best-effort; span naming must never break the response.
        }
    }

    /**
     * Prefers the router's named route, falling back to its raw pattern
     * (matching how sibling packages, e.g. Slim, name their root span) —
     * annotateWithRoute() falls back further to controller/action when
     * there is no router/matched route at all.
     *
     * `Phalcon\Mvc\Router`'s own default two routes (attached by
     * `new Router()`/`new Router(true)`, which is what `Di\FactoryDefault`
     * gives every app that doesn't build its own router) are defined
     * directly as raw PCRE patterns — `getPattern()` on them returns the
     * literal regex string (e.g. `#^/([\w0-9\_\-]+)/([\w0-9\.\_]+)(/.*)?$#u`),
     * not a human-readable route. Phalcon's own PCRE delimiter for these is
     * `#`, so a pattern starting with it is skipped in favour of the
     * controller/action fallback rather than surfaced as a span name.
     */
    private static function matchedRouteLabel(DiInterface $di): ?string
    {
        if (!$di->has('router')) {
            return null;
        }

        try {
            $router = $di->getShared('router');
        } catch (Throwable) {
            return null;
        }

        if (!$router instanceof RouterInterface) {
            return null;
        }

        $route = $router->getMatchedRoute();
        if (!$route instanceof RouteInterface) {
            return null;
        }

        $name = $route->getName();
        if ($name !== null && $name !== '') {
            return $name;
        }

        $pattern = $route->getPattern();

        return ($pattern !== '' && !str_starts_with($pattern, '#')) ? $pattern : null;
    }

    /**
     * Resolves the in-flight Response via the target's own DI container —
     * the same shared `Phalcon\Http\Response` instance regardless of
     * whether the app reaches it through `Mvc\Application`, `Mvc\Micro`, or
     * by calling the dispatcher directly. `getShared()`, not `get()`: the
     * latter constructs a *new* instance for any service not explicitly
     * registered as shared, which would silently read/report on the wrong
     * object.
     */
    private static function resolveResponse(object $target): ?ResponseInterface
    {
        $di = self::resolveDi($target);
        if ($di === null || !$di->has('response')) {
            return null;
        }

        try {
            $response = $di->getShared('response');
        } catch (Throwable) {
            return null;
        }

        return $response instanceof ResponseInterface ? $response : null;
    }

    private static function resolveDi(object $target): ?DiInterface
    {
        if (!method_exists($target, 'getDI')) {
            return null;
        }

        try {
            $di = $target->getDI();
        } catch (Throwable) {
            return null;
        }

        return $di instanceof DiInterface ? $di : null;
    }

    /**
     * `AbstractDispatcher` is the shared base of both `Phalcon\Mvc\Dispatcher`
     * (HTTP) and `Phalcon\Cli\Dispatcher` (CLI) — `getControllerName()` is
     * declared on the former only (the latter has `getTaskName()` instead),
     * so its presence is used to tell which kind of dispatch this is.
     */
    private static function isHttpDispatcher(AbstractDispatcher $target): bool
    {
        return method_exists($target, 'getControllerName');
    }

    /** @return array{0: string, 1: string} controller-or-task name, action name */
    private static function dispatcherName(AbstractDispatcher $target): array
    {
        $name = '';
        $action = '';

        try {
            if (method_exists($target, 'getControllerName')) {
                $name = $target->getControllerName();
            } elseif (method_exists($target, 'getTaskName')) {
                $name = $target->getTaskName();
            }
            $action = $target->getActionName();
        } catch (Throwable) {
            // Naming is best-effort; must never break the response.
        }

        return [$name, $action];
    }

    /**
     * True exactly for the one callActionMethod firing that is the real,
     * outermost-for-this-call invocation — see class docblock. `$class` is
     * the executing frame's declaring scope; comparing it against the
     * target's actual polymorphic resolution for `callActionMethod`
     * (cached per runtime class, since class hierarchies don't change at
     * runtime) distinguishes a subclass's own override from its internal
     * `parent::callActionMethod()` call, and correctly still spans a
     * separate, genuinely nested action invocation on the same target.
     */
    private static function isRealActionInvocation(AbstractDispatcher $target, string $class): bool
    {
        $targetClass = $target::class;

        if (!isset(self::$declaringClassCache[$targetClass])) {
            try {
                self::$declaringClassCache[$targetClass] = (new ReflectionMethod($target, 'callActionMethod'))
                    ->getDeclaringClass()
                    ->getName();
            } catch (Throwable) {
                self::$declaringClassCache[$targetClass] = $class;
            }
        }

        return self::$declaringClassCache[$targetClass] === $class;
    }

    /**
     * Builds a propagation carrier from every inbound HTTP header found in
     * $_SERVER, rather than hardcoding W3C tracecontext's `traceparent`/
     * `tracestate` — lets the app's configured propagator (tracecontext,
     * B3, etc.) read whatever headers it actually needs.
     *
     * @return array<string, string>
     */
    private static function carrierFromServerGlobals(): array
    {
        $carrier = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value) || !str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $header = strtolower(str_replace('_', '-', substr($key, 5)));
            $carrier[$header] = $value;
        }

        return $carrier;
    }
}

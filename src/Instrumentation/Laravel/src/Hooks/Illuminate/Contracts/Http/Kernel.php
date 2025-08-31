<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Contracts\Http;

use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context as InstrumentationContext;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelInstrumentation;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Propagators\HeadersPropagator;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Propagators\ResponsePropagationSetter;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Kernel implements Hook
{
    use PostHookTrait;

    public function instrument(
        LaravelInstrumentation $instrumentation,
        HookManagerInterface $hookManager,
        InstrumentationContext $context,
    ): void {
        $tracer = $context->tracerProvider->getTracer(
            $instrumentation->buildProviderName('http', 'kernel'),
            schemaUrl: Version::VERSION_1_24_0->url(),
        );

        $this->hookHandle($hookManager, $tracer, $context->propagator);
    }

    /** @psalm-suppress PossiblyUnusedReturnValue  */
    protected function hookHandle(
        HookManagerInterface $hookManager,
        TracerInterface $tracer,
        TextMapPropagatorInterface $propagator,
    ): void {
        $hookManager->hook(
            KernelContract::class,
            'handle',
            preHook: function (KernelContract $kernel, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer, $propagator) {
                $request = ($params[0] instanceof Request) ? $params[0] : null;
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $tracer
                    ->spanBuilder(sprintf('%s', $request?->method() ?? 'unknown'))
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);
                $parent = Context::getCurrent();
                if ($request) {
                    /** @phan-suppress-next-line PhanAccessMethodInternal */
                    $parent = $propagator->extract($request, HeadersPropagator::instance());
                    $span = $builder
                        ->setParent($parent)
                        ->setAttribute(TraceAttributes::URL_FULL, $request->fullUrl())
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->method())
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->header('Content-Length'))
                        ->setAttribute(TraceAttributes::URL_SCHEME, $request->getScheme())
                        ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                        ->setAttribute(TraceAttributes::NETWORK_PEER_ADDRESS, $request->server('REMOTE_ADDR'))
                        ->setAttribute(TraceAttributes::URL_PATH, $this->httpTarget($request))
                        ->setAttribute(TraceAttributes::SERVER_ADDRESS, $this->httpHostName($request))
                        ->setAttribute(TraceAttributes::SERVER_PORT, $request->getPort())
                        ->setAttribute(TraceAttributes::CLIENT_PORT, $request->server('REMOTE_PORT'))
                        ->setAttribute(TraceAttributes::CLIENT_ADDRESS, $request->ip())
                        ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->userAgent())
                        ->startSpan();
                    $request->attributes->set(SpanInterface::class, $span);
                } else {
                    $span = $builder->startSpan();
                }
                Context::storage()->attach($span->storeInContext($parent));

                return [$request];
            },
            postHook: function (KernelContract $kernel, array $params, ?Response $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $span = Span::fromContext($scope->context());

                $request = ($params[0] instanceof Request) ? $params[0] : null;
                $route = $request?->route();

                if ($request && $route instanceof Route) {
                    $span->updateName("{$request->method()} /" . ltrim($route->uri, '/'));
                    $span->setAttribute(TraceAttributes::HTTP_ROUTE, $route->uri);
                }

                if ($response) {
                    if ($response->getStatusCode() >= 500) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, $response->headers->get('Content-Length'));

                    // Propagate server-timing header to response, if ServerTimingPropagator is present
                    if (class_exists('OpenTelemetry\Contrib\Propagation\ServerTiming\ServerTimingPropagator')) {
                        /** @phan-suppress-next-line PhanUndeclaredClassMethod */
                        $prop = new \OpenTelemetry\Contrib\Propagation\ServerTiming\ServerTimingPropagator();
                        /** @phan-suppress-next-line PhanAccessMethodInternal,PhanUndeclaredClassMethod */
                        $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());
                    }

                    // Propagate traceresponse header to response, if TraceResponsePropagator is present
                    if (class_exists('OpenTelemetry\Contrib\Propagation\TraceResponse\TraceResponsePropagator')) {
                        /** @phan-suppress-next-line PhanUndeclaredClassMethod */
                        $prop = new \OpenTelemetry\Contrib\Propagation\TraceResponse\TraceResponsePropagator();
                        /** @phan-suppress-next-line PhanAccessMethodInternal,PhanUndeclaredClassMethod */
                        $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());
                    }
                }

                $this->endSpan($exception);
            }
        );
    }

    private function httpTarget(Request $request): string
    {
        $query = $request->getQueryString();
        $question = $request->getBaseUrl() . $request->getPathInfo() === '/' ? '/?' : '?';

        return $query ? $request->path() . $question . $query : $request->path();
    }

    private function httpHostName(Request $request): string
    {
        if (method_exists($request, 'host')) {
            return $request->host();
        }

        if (method_exists($request, 'getHost')) {
            return $request->getHost();
        }

        return '';
    }
}

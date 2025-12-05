<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Contracts\Http;

use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use OpenTelemetry\API\Globals;
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
use OpenTelemetry\SemConv\Attributes\ClientAttributes;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use OpenTelemetry\SemConv\Version;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** @psalm-suppress UnusedClass */
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
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                $parent = Context::getCurrent();
                if ($request) {
                    /** @phan-suppress-next-line PhanAccessMethodInternal */
                    $parent = $propagator->extract($request, HeadersPropagator::instance());
                    $span = $builder
                        ->setParent($parent)
                        ->setAttribute(UrlAttributes::URL_FULL, $request->fullUrl())
                        ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $request->method())
                        ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $request->header('Content-Length'))
                        ->setAttribute(UrlAttributes::URL_SCHEME, $request->getScheme())
                        ->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                        ->setAttribute(NetworkAttributes::NETWORK_PEER_ADDRESS, $request->server('REMOTE_ADDR'))
                        ->setAttribute(UrlAttributes::URL_PATH, $this->httpTarget($request))
                        ->setAttribute(ServerAttributes::SERVER_ADDRESS, $this->httpHostName($request))
                        ->setAttribute(ServerAttributes::SERVER_PORT, $request->getPort())
                        ->setAttribute(ClientAttributes::CLIENT_PORT, $request->server('REMOTE_PORT'))
                        ->setAttribute(ClientAttributes::CLIENT_ADDRESS, $request->ip())
                        ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->userAgent())
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
                    $span->setAttribute(HttpAttributes::HTTP_ROUTE, $route->uri);
                }

                if ($response) {
                    if ($response->getStatusCode() >= 500) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                    $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                    $span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, $response->headers->get('Content-Length'));

                    $prop = Globals::responsePropagator();
                    /** @phan-suppress-next-line PhanAccessMethodInternal */
                    $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());
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

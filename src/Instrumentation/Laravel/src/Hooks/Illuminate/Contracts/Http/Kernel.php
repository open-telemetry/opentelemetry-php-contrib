<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Contracts\Http;

use Http\Discovery\Psr17FactoryDiscovery;
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
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;
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

    #[\Override]
    public function instrument(
        LaravelConfiguration $configuration,
        HookManagerInterface $hookManager,
        InstrumentationContext $context,
    ): void {
        $tracer = $context->tracerProvider->getTracer(
            LaravelInstrumentation::buildProviderName('http', 'kernel'),
            schemaUrl: Version::VERSION_1_24_0->url(),
        );

        $this->hookHandle($hookManager, $configuration, $context, $tracer);
    }

    /** @psalm-suppress ArgumentTypeCoercion,PossiblyUnusedReturnValue */
    protected function hookHandle(
        HookManagerInterface $hookManager,
        LaravelConfiguration $configuration,
        InstrumentationContext $context,
        TracerInterface $tracer,
    ): void {
        $hookManager->hook(
            KernelContract::class,
            'handle',
            preHook: function (KernelContract $kernel, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($configuration, $context, $tracer) {
                $parent = Context::getCurrent();
                $request = ($params[0] instanceof Request) ? $params[0] : null;
                $method = $request ? $this->httpMethod($request) : 'unknown';
                /** @psalm-suppress ArgumentTypeCoercion */
                $spanBuilder = $tracer
                    ->spanBuilder($method)
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);

                if ($request) {
                    /** @phan-suppress-next-line PhanAccessMethodInternal */
                    $parent = $context->propagator->extract($request, HeadersPropagator::instance());

                    $spanBuilder
                        ->setParent($parent)
                        ->setAttribute(ClientAttributes::CLIENT_ADDRESS, $request->ip())
                        ->setAttribute(ClientAttributes::CLIENT_PORT, $request->server('REMOTE_PORT'))
                        ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $method)
                        ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $request->header('Content-Length'))
                        ->setAttribute(NetworkAttributes::NETWORK_PEER_ADDRESS, $request->server('REMOTE_ADDR'))
                        ->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                        ->setAttribute(ServerAttributes::SERVER_ADDRESS, $this->httpHostName($request) ?: null)
                        ->setAttribute(ServerAttributes::SERVER_PORT, $request->getPort())
                        ->setAttribute(UrlAttributes::URL_PATH, $this->httpPath($request))
                        ->setAttribute(UrlAttributes::URL_QUERY, $request->getQueryString())
                        ->setAttribute(UrlAttributes::URL_SCHEME, $request->getScheme())
                        ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->userAgent());

                    try {
                        $uri = Psr17FactoryDiscovery::findUriFactory()->createUri($request->getUri());
                        $sanitizedUri = $configuration->getHttpConfig()?->sanitizer->sanitize($uri);

                        if ($sanitizedUri) {
                            $spanBuilder->setAttributes([
                                UrlAttributes::URL_FULL => vsprintf('%s://%s%s%s', [
                                    $sanitizedUri->getScheme(),
                                    $sanitizedUri->getHost(),
                                    $sanitizedUri->getPath(),
                                    !empty($sanitizedUri->getQuery()) ? '?' . $sanitizedUri->getQuery() : null,
                                ]),
                                UrlAttributes::URL_PATH => $sanitizedUri->getPath(),
                                UrlAttributes::URL_QUERY => $sanitizedUri->getQuery() ?: null,
                                UrlAttributes::URL_SCHEME => $sanitizedUri->getScheme(),
                            ]);
                        }
                    } catch (Throwable) {
                        // Uri parsing has failed.
                    }

                    $span = $spanBuilder->startSpan();
                    $request->attributes->set(SpanInterface::class, $span);
                } else {
                    $span = $spanBuilder->startSpan();
                }

                Context::storage()->attach($span->storeInContext($parent));

                return [$request];
            },
            postHook: function (KernelContract $kernel, array $params, ?Response $response, ?Throwable $exception) use ($context) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $span = Span::fromContext($scope->context());

                $request = ($params[0] instanceof Request) ? $params[0] : null;
                $route = $request?->route();

                if ($request && $route instanceof Route) {
                    $span->updateName($this->httpMethod($request) . ' /' . ltrim($route->uri, '/'));
                    $span->setAttribute(HttpAttributes::HTTP_ROUTE, $route->uri);
                }

                if ($response) {
                    if ($response->getStatusCode() >= 500) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                    $span->setAttributes([
                        HttpAttributes::HTTP_RESPONSE_STATUS_CODE => $response->getStatusCode(),
                        NetworkAttributes::NETWORK_PROTOCOL_VERSION => $response->getProtocolVersion(),
                        HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE => $response->headers->has('Content-Length')
                            ? (int) $response->headers->get('Content-Length')
                            : null,
                    ]);

                    $context->responsePropagator->inject($response, ResponsePropagationSetter::instance(), $scope->context());
                }

                $this->endSpan($exception);
            }
        );
    }

    private function httpPath(Request $request): string
    {
        return $request->getBaseUrl() . $request->getPathInfo();
    }

    private function httpMethod(Request $request): string
    {
        try {
            return $request->method();
        } catch (Throwable) {
            return 'unknown';
        }
    }

    private function httpHostName(Request $request): string
    {
        try {
            return $request->host();
        } catch (Throwable) {
            return '';
        }
    }
}

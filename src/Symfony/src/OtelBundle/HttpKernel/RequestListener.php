<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelBundle\HttpKernel;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\Symfony\OtelBundle\OtelBundle;
use function sprintf;
use function strtolower;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final class RequestListener implements EventSubscriberInterface
{
    private const REQUEST_ATTRIBUTE_SPAN = '__otel_contrib_internal_span';
    private const REQUEST_ATTRIBUTE_SCOPE = '__otel_contrib_internal_scope';
    private const REQUEST_ATTRIBUTE_EXCEPTION = '__otel_contrib_internal_exception';
    private const TRACE_ATTRIBUTE_HTTP_HOST = 'http.host';
    private const TRACE_ATTRIBUTE_HTTP_USER_AGENT = 'http.user_agent';
    private const TRACE_ATTRIBUTE_NET_PEER_IP = 'net.peer.ip';
    private const TRACE_ATTRIBUTE_NET_HOST_IP = 'net.host.ip';

    private TracerInterface $tracer;
    private TextMapPropagatorInterface $propagator;
    private PropagationGetterInterface $propagationGetter;
    private array $requestHeaderAttributes;
    private array $responseHeaderAttributes;

    /**
     * @param iterable<string> $requestHeaders
     * @param iterable<string> $responseHeaders
     */
    public function __construct(
        TracerProviderInterface $tracerProvider,
        TextMapPropagatorInterface $propagator,
        iterable $requestHeaders = [],
        iterable $responseHeaders = []
    ) {
        $this->tracer = $tracerProvider->getTracer(
            OtelBundle::instrumentationName(),
            OtelBundle::instrumentationVersion(),
            TraceAttributes::SCHEMA_URL,
        );
        $this->propagator = $propagator;
        $this->propagationGetter = new HeadersPropagator();
        $this->requestHeaderAttributes = $this->createHeaderAttributeMapping('request', $requestHeaders);
        $this->responseHeaderAttributes = $this->createHeaderAttributeMapping('response', $responseHeaders);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['startRequest', 10000],
                ['recordRoute', 31], // after RouterListener
            ],
            KernelEvents::EXCEPTION => [
                ['recordException'],
            ],
            KernelEvents::RESPONSE => [
                ['recordResponse', -10000],
            ],
            KernelEvents::FINISH_REQUEST => [
                ['endScope', -10000],
                ['endRequest', -10000],
            ],
            KernelEvents::TERMINATE => [
                ['terminateRequest', 10000],
            ],
        ];
    }

    public function startRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        /** @psalm-suppress ArgumentTypeCoercion */
        $spanBuilder = $this->tracer
            /** @phan-suppress-next-line PhanTypeMismatchArgument */
            ->spanBuilder(sprintf('HTTP %s', $request->getMethod()))
            ->setAttributes($this->requestAttributes($request))
            ->setAttributes($this->headerAttributes($request->headers, $this->requestHeaderAttributes))
        ;

        $parent = Context::getCurrent();

        if ($event->isMainRequest()) {
            $spanBuilder->setSpanKind(SpanKind::KIND_SERVER);
            $parent = $this->propagator->extract(
                $request,
                $this->propagationGetter,
                $parent,
            );

            if ($requestTime = $request->server->get('REQUEST_TIME_FLOAT')) {
                $spanBuilder->setStartTimestamp((int) ($requestTime * 1_000_000_000));
            }
        }

        $span = $spanBuilder->setParent($parent)->startSpan();
        $scope = $span->storeInContext($parent)->activate();

        $request->attributes->set(self::REQUEST_ATTRIBUTE_SPAN, $span);
        $request->attributes->set(self::REQUEST_ATTRIBUTE_SCOPE, $scope);
    }

    public function recordRoute(RequestEvent $event): void
    {
        if (!$span = $this->fetchRequestSpan($event->getRequest())) {
            return;
        }

        if (($routeName = $event->getRequest()->attributes->get('_route', '')) === '') {
            return;
        }

        /** @phan-suppress-next-line PhanTypeMismatchArgument */
        $span->updateName($routeName);
        $span->setAttribute(TraceAttributes::HTTP_ROUTE, $routeName);
    }

    public function recordException(ExceptionEvent $event): void
    {
        if (!$span = $this->fetchRequestSpan($event->getRequest())) {
            return;
        }

        $span->recordException($event->getThrowable());
        $event->getRequest()->attributes->set(self::REQUEST_ATTRIBUTE_EXCEPTION, $event->getThrowable());
    }

    public function recordResponse(ResponseEvent $event): void
    {
        if (!$span = $this->fetchRequestSpan($event->getRequest())) {
            return;
        }

        $event->getRequest()->attributes->remove(self::REQUEST_ATTRIBUTE_EXCEPTION);

        if (!$span->isRecording()) {
            return;
        }

        $response = $event->getResponse();
        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_CONTENT_LENGTH, $response->headers->get('Content-Length'));
        $span->setAttribute(TraceAttributes::HTTP_STATUS_CODE, $response->getStatusCode());
        if ($response->getStatusCode() >= 500 && $response->getStatusCode() < 600) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }

        $span->setAttributes($this->headerAttributes($response->headers, $this->responseHeaderAttributes));
    }

    public function endScope(FinishRequestEvent $event): void
    {
        if (!$scope = $this->fetchRequestScope($event->getRequest())) {
            return;
        }

        $scope->detach();
    }

    public function endRequest(FinishRequestEvent $event): void
    {
        if (!$span = $this->fetchRequestSpan($event->getRequest())) {
            return;
        }

        if ($exception = $this->fetchRequestException($event->getRequest())) {
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        } elseif ($event->isMainRequest()) {
            // End span on ::terminateRequest() instead
            return;
        }

        $span->end();
    }

    public function terminateRequest(TerminateEvent $event): void
    {
        if (!$span = $this->fetchRequestSpan($event->getRequest())) {
            return;
        }

        $span->end();
    }

    private function fetchRequestSpan(Request $request): ?SpanInterface
    {
        return $this->fetchRequestAttribute($request, self::REQUEST_ATTRIBUTE_SPAN, SpanInterface::class);
    }

    private function fetchRequestScope(Request $request): ?ScopeInterface
    {
        return $this->fetchRequestAttribute($request, self::REQUEST_ATTRIBUTE_SCOPE, ScopeInterface::class);
    }

    private function fetchRequestException(Request $request): ?Throwable
    {
        return $this->fetchRequestAttribute($request, self::REQUEST_ATTRIBUTE_EXCEPTION, Throwable::class);
    }

    /**
     * @psalm-template T of object
     * @psalm-param class-string<T> $type
     * @psalm-return T|null
     */
    private function fetchRequestAttribute(Request $request, string $key, string $type): ?object
    {
        return ($object = $request->attributes->get($key)) instanceof $type
            ? $object
            : null;
    }

    private function requestAttributes(Request $request): iterable
    {
        return [
            TraceAttributes::HTTP_METHOD => $request->getMethod(),
            TraceAttributes::HTTP_TARGET => $request->getRequestUri(),
            self::TRACE_ATTRIBUTE_HTTP_HOST => $request->getHttpHost(),
            TraceAttributes::HTTP_SCHEME => $request->getScheme(),
            TraceAttributes::HTTP_FLAVOR => ($protocolVersion = $request->getProtocolVersion()) !== null
                ? strtr($protocolVersion, ['HTTP/' => ''])
                : null,
            self::TRACE_ATTRIBUTE_HTTP_USER_AGENT => $request->headers->get('User-Agent'),
            TraceAttributes::HTTP_REQUEST_CONTENT_LENGTH => $request->headers->get('Content-Length'),
            TraceAttributes::HTTP_CLIENT_IP => $request->getClientIp(),

            self::TRACE_ATTRIBUTE_NET_PEER_IP => $request->server->get('REMOTE_ADDR'),
            TraceAttributes::NET_PEER_PORT => $request->server->get('REMOTE_PORT'),
            TraceAttributes::NET_PEER_NAME => $request->server->get('REMOTE_HOST'),
            self::TRACE_ATTRIBUTE_NET_HOST_IP => $request->server->get('SERVER_ADDR'),
            TraceAttributes::NET_HOST_PORT => $request->server->get('SERVER_PORT'),
            TraceAttributes::NET_HOST_NAME => $request->server->get('SERVER_NAME'),
        ];
    }

    private function headerAttributes(HeaderBag $headerBag, array $headers): iterable
    {
        foreach ($headers as $header => $attribute) {
            if ($headerBag->has($header)) {
                yield $attribute => $headerBag->all($header);
            }
        }
    }

    private function createHeaderAttributeMapping(string $type, iterable $headers): array
    {
        $headerAttributes = [];
        foreach ($headers as $header) {
            $lcHeader = strtolower($header);
            $headerAttributes[$lcHeader] = sprintf('http.%s.header.%s', $type, strtr($lcHeader, ['-' => '_']));
        }

        return $headerAttributes;
    }
}

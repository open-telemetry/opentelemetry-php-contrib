<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Psr18;

use OpenTelemetry\API\Trace\AbstractSpan;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use function sprintf;
use function strtolower;
use Throwable;

function enableHttpClientTracing(TracerInterface $tracer, TextMapPropagatorInterface $propagator, iterable $requestHeaders = [], iterable $responseHeaders = []): void
{
    hook(
        ClientInterface::class,
        'sendRequest',
        static function (ClientInterface $client, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer, $propagator, $requestHeaders): ?array {
            $request = $params[0] ?? null;
            if (!$request instanceof RequestInterface) {
                Context::getCurrent()->activate();

                return null;
            }

            $parentContext = Context::getCurrent();

            $spanBuilder = $tracer
                ->spanBuilder(sprintf('HTTP %s', $request->getMethod()))
                ->setParent($parentContext)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setAttribute('http.url', (string) $request->getUri())
                ->setAttribute('http.method', $request->getMethod())
                ->setAttribute('http.flavor', $request->getProtocolVersion())
                ->setAttribute('http.host', $request->getHeaderLine('Host'))
                ->setAttribute('http.user_agent', $request->getHeaderLine('User-Agent'))
                ->setAttribute('http.request_content_length', $request->getHeaderLine('Content-Length'))
                ->setAttribute('code.function', $function)
                ->setAttribute('code.namespace', $class)
                ->setAttribute('code.filepath', $filename)
                ->setAttribute('code.lineno', $lineno)
            ;

            foreach ($propagator->fields() as $field) {
                $request = $request->withoutHeader($field);
            }
            foreach ($requestHeaders as $header) {
                if ($request->hasHeader($header)) {
                    $spanBuilder->setAttribute(sprintf('http.request.header.%s', strtr(strtolower($header), ['-' => '_'])), $request->getHeader($header));
                }
            }

            $span = $spanBuilder->startSpan();
            $context = $span->storeInContext($parentContext);
            $propagator->inject($request, HeadersPropagator::Instance, $context);

            $context->activate();

            return [$request];
        },
        static function (ClientInterface $client, array $params, ?ResponseInterface $response, ?Throwable $exception) use ($responseHeaders): void {
            $scope = Context::storage()->scope();
            $scope?->detach();

            if (!$scope || $scope->context() === Context::getCurrent()) {
                return;
            }

            $span = AbstractSpan::fromContext($scope->context());

            if ($response) {
                $span->setAttribute('http.status_code', $response->getStatusCode());
                $span->setAttribute('http.flavor', $response->getProtocolVersion());
                $span->setAttribute('http.response_content_length', $response->getHeaderLine('Content-Length'));

                foreach ($responseHeaders as $header) {
                    if ($response->hasHeader($header)) {
                        $span->setAttribute(sprintf('http.response.header.%s', strtr(strtolower($header), ['-' => '_'])), $response->getHeader($header));
                    }
                }
                if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 600) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }
            }
            if ($exception) {
                $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            }

            $span->end();
        },
    );
}

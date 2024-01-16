<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Guzzle;

use function get_cfg_var;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use function sprintf;
use function strtolower;
use Throwable;

class GuzzleInstrumentation
{
    /** @psalm-suppress ArgumentTypeCoercion */
    public const NAME = 'guzzle';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.guzzle', schemaUrl: TraceAttributes::SCHEMA_URL);

        hook(
            ClientInterface::class,
            'transfer',
            pre: static function (ClientInterface $client, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation): array {
                $request = $params[0];
                assert($request instanceof RequestInterface);

                $propagator = Globals::propagator();
                $parentContext = Context::getCurrent();

                /** @psalm-suppress ArgumentTypeCoercion */
                $spanBuilder = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('%s', $request->getMethod()))
                    ->setParent($parentContext)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::URL_FULL, (string) $request->getUri())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                    ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                    ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                    ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                    ->setAttribute(TraceAttributes::SERVER_PORT, $request->getUri()->getPort())
                    ->setAttribute(TraceAttributes::URL_PATH, $request->getUri()->getPath())
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                ;

                foreach ($propagator->fields() as $field) {
                    $request = $request->withoutHeader($field);
                }
                foreach ((array) (get_cfg_var('otel.instrumentation.http.request_headers') ?: []) as $header) {
                    if ($request->hasHeader($header)) {
                        $spanBuilder->setAttribute(
                            sprintf('http.request.header.%s', strtolower($header)),
                            $request->getHeader($header)
                        );
                    }
                }

                $span = $spanBuilder->startSpan();
                $context = $span->storeInContext($parentContext);
                $propagator->inject($request, HeadersPropagator::instance(), $context);

                Context::storage()->attach($context);

                return [$request];
            },
            post: static function (ClientInterface $client, array $params, PromiseInterface $promise, ?Throwable $exception): void {
                $scope = Context::storage()->scope();
                $scope?->detach();

                if (!$scope || $scope->context() === Context::getCurrent()) {
                    return;
                }

                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                    $span->end();
                }

                $promise->then(
                    onFulfilled: function (ResponseInterface $response) use ($span) {
                        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                        $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, $response->getHeaderLine('Content-Length'));
                        if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 600) {
                            $span->setStatus(StatusCode::STATUS_ERROR);
                        }
                        $span->end();

                        return $response;
                    },
                    onRejected: function (\Throwable $t) use ($span) {
                        $span->recordException($t, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                        $span->setStatus(StatusCode::STATUS_ERROR, $t->getMessage());
                        $span->end();

                        throw $t;
                    }
                );
            }
        );
    }
}

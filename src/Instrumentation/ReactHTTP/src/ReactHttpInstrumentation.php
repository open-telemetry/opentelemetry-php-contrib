<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\ReactHttp;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ResponseInterface;
use React\Http\Io\Transaction;
use React\Http\Message\ResponseException;
use React\Promise\PromiseInterface;
use Throwable;

/** @psalm-suppress UnusedClass */
class ReactHttpInstrumentation
{
    public const NAME = 'react-http';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.react-http',
            schemaUrl: 'https://opentelemetry.io/schemas/1.32.0',
        );

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            Transaction::class,
            'send',
            pre: static function (Transaction $transaction, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation): ?array {
                $request = $params[0];

                $propagator = Globals::propagator();
                $parentContext = Context::getCurrent();

                foreach ($propagator->fields() as $field) {
                    $request = $request->withoutHeader($field);
                }

                /** @psalm-suppress ArgumentTypeCoercion */
                $spanBuilder = $instrumentation
                    ->tracer()
                    // https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span
                    ->spanBuilder(sprintf('%s', $request->getMethod()))
                    ->setParent($parentContext)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                    ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                    ->setAttribute(TraceAttributes::SERVER_PORT, $request->getUri()->getPort())
                    ->setAttribute(TraceAttributes::URL_FULL, (string) $request->getUri())
                    ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_NAME, 'http')
                    ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                    ->setAttribute(TraceAttributes::URL_SCHEME, $request->getUri()->getScheme())
                    ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                    // https://opentelemetry.io/docs/specs/semconv/code/
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);

                /**
                 * This cannot be tested with PHPUnit without modifying php.ini with:
                 * otel.instrumentation.http.request_headers[]="Accept"
                 */
                // @codeCoverageIgnoreStart
                foreach ((array) (get_cfg_var('otel.instrumentation.http.request_headers') ?: []) as $header) {
                    if ($request->hasHeader($header)) {
                        $spanBuilder->setAttribute(
                            sprintf('%s.%s', TraceAttributes::HTTP_REQUEST_HEADER, strtolower($header)),
                            $request->getHeaderLine($header)
                        );
                    }
                }
                // @codeCoverageIgnoreEnd

                $span = $spanBuilder->startSpan();
                $context = $span->storeInContext($parentContext);
                $propagator->inject($request, HeadersPropagator::instance(), $context);

                Context::storage()->attach($context);

                return [$request];
            },
            post: static function (Transaction $transaction, array $params, PromiseInterface $promise, ?Throwable $exception): PromiseInterface {
                $scope = Context::storage()->scope();
                $scope?->detach();

                if (!$scope || $scope->context() === Context::getCurrent()) {
                    return $promise;
                }

                $span = Span::fromContext($scope->context());

                return $promise->then(
                    onFulfilled: function (ResponseInterface $response) use ($span) {
                        $span
                            ->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode())
                            ->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, $response->getHeaderLine('Content-Length'));

                        /**
                         * This cannot be tested with PHPUnit without modifying php.ini with:
                         * otel.instrumentation.http.response_headers[]="Content-Type"
                         */
                        // @codeCoverageIgnoreStart
                        foreach ((array) (get_cfg_var('otel.instrumentation.http.response_headers') ?: []) as $header) {
                            if ($response->hasHeader($header)) {
                                /** @psalm-suppress ArgumentTypeCoercion */
                                $span->setAttribute(
                                    sprintf('%s.%s', TraceAttributes::HTTP_RESPONSE_HEADER, strtolower($header)),
                                    $response->getHeaderLine($header)
                                );
                            }
                        }
                        // @codeCoverageIgnoreEnd

                        $span->end();

                        return $response;
                    },
                    onRejected: function (Throwable $t) use ($span) {
                        $span->recordException($t);
                        if (is_a($t, ResponseException::class)) {
                            $span
                                ->setStatus(StatusCode::STATUS_ERROR)
                                ->setAttribute(TraceAttributes::ERROR_TYPE, (string) $t->getCode())
                                ->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $t->getCode())
                                ->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, $t->getResponse()->getHeaderLine('Content-Length'));

                            // @codeCoverageIgnoreStart
                            foreach ((array) (get_cfg_var('otel.instrumentation.http.response_headers') ?: []) as $header) {
                                if ($t->getResponse()->hasHeader($header)) {
                                    /** @psalm-suppress ArgumentTypeCoercion */
                                    $span->setAttribute(
                                        sprintf('%s.%s', TraceAttributes::HTTP_RESPONSE_HEADER, strtolower($header)),
                                        $t->getResponse()->getHeaderLine($header)
                                    );
                                }
                            }
                            // @codeCoverageIgnoreEnd
                        } else {
                            $span
                                ->setStatus(StatusCode::STATUS_ERROR, $t->getMessage())
                                ->setAttribute(TraceAttributes::ERROR_TYPE, $t::class);
                        }

                        $span->end();

                        throw $t;
                    }
                );
            }
        );
    }
}

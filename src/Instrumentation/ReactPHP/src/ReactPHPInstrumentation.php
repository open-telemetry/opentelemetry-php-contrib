<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\ReactPHP;

use Composer\InstalledVersions;
use GuzzleHttp\Psr7\Query;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\TraceAttributeValues;
use OpenTelemetry\SemConv\Version;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use React\Http\Io\Transaction;
use React\Http\Message\ResponseException;
use React\Promise\PromiseInterface;
use Throwable;

/** @psalm-suppress UnusedClass */
class ReactPHPInstrumentation
{
    public const NAME = 'reactphp';
    /**
     * The name of the Composer package.
     *
     * @see https://getcomposer.org/doc/04-schema.md#name
     */
    private const COMPOSER_NAME = 'open-telemetry/opentelemetry-auto-reactphp';
    /**
     * The environment variable which overrides the default list of known HTTP methods.
     * This supports a comma-separated list of case-sensitive known HTTP methods.
     *
     * @see https://opentelemetry.io/docs/specs/semconv/attributes-registry/http/#http-request-method
     */
    private const ENV_HTTP_KNOWN_METHODS = 'OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS';
    /**
     * The environment variable which configures the request headers to be captured (default is none).
     * This supports a comma-separated list of case-insensitive HTTP header keys.
     *
     * @see https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span
     */
    private const ENV_HTTP_REQUEST_HEADERS = 'OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS';
    /**
     * The environment variable which configures the response headers to be captured (default is none).
     * This supports a comma-separated list of case-insensitive HTTP header keys.
     *
     * @see https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span
     */
    private const ENV_HTTP_RESPONSE_HEADERS = 'OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS';
    /**
     * The `{method}` component of the span name when the original method is not known to the instrumentation.
     *
     * @see https://opentelemetry.io/docs/specs/semconv/http/http-spans/#name
     */
    private const HTTP_REQUEST_METHOD_HTTP = 'HTTP';
    /**
     * Name of this instrumentation library which provides the instrumentation for ReactPHP.
     *
     * @see https://opentelemetry.io/docs/specs/otel/glossary/#instrumentation-library
     */
    private const INSTRUMENTATION_LIBRARY_NAME = 'io.opentelemetry.contrib.php.reactphp';
    /**
     * Query string keys to be redacted by default.
     *
     * @see https://opentelemetry.io/docs/specs/semconv/attributes-registry/url/#url-query
     */
    private const URL_QUERY_REDACT_KEYS = ['AWSAccessKeyId', 'Signature', 'sig', 'X-Goog-Signature'];
    /**
     * Value used to replace any sensitive content in the URL.
     *
     * @see https://opentelemetry.io/docs/specs/semconv/attributes-registry/url/#url-full
     */
    private const URL_REDACTION = 'REDACTED';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            self::INSTRUMENTATION_LIBRARY_NAME,
            InstalledVersions::getPrettyVersion(self::COMPOSER_NAME),
            Version::VERSION_1_32_0->url()
        );

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            Transaction::class,
            'send',
            pre: static function (Transaction $transaction, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation): array {
                /** @var \Psr\Http\Message\RequestInterface */
                $request = $params[0];

                $propagator = Globals::propagator();
                $parentContext = Context::getCurrent();

                foreach ($propagator->fields() as $field) {
                    $request = $request->withoutHeader($field);
                }

                /** @var non-empty-string|null */
                $method = self::canonizeMethod($request->getMethod());

                $spanBuilder = $instrumentation
                    ->tracer()
                    // https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span
                    ->spanBuilder($method ?? self::HTTP_REQUEST_METHOD_HTTP)
                    ->setParent($parentContext)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $method ?? TraceAttributeValues::HTTP_REQUEST_METHOD_OTHER)
                    ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                    ->setAttribute(TraceAttributes::SERVER_PORT, $request->getUri()->getPort() ?? ($request->getUri()->getScheme() === 'https' ? 443 : 80))
                    ->setAttribute(TraceAttributes::URL_FULL, self::sanitizeUrl($request->getUri()))
                    // https://opentelemetry.io/docs/specs/semconv/code/
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function));

                // https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span
                if ($method === null) {
                    $spanBuilder->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD_ORIGINAL, $request->getMethod());
                }

                // https://opentelemetry.io/docs/specs/semconv/code/
                /** @psalm-suppress RiskyTruthyFalsyComparison */
                if ($filename) {
                    $spanBuilder->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename);
                }
                /** @psalm-suppress RiskyTruthyFalsyComparison */
                if ($lineno) {
                    $spanBuilder->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);
                }

                $span = $spanBuilder->startSpan();
                $context = $span->storeInContext($parentContext);
                $propagator->inject($request, HeadersPropagator::instance(), $context);

                foreach (explode(',', $_ENV[self::ENV_HTTP_REQUEST_HEADERS] ?? '') as $header) {
                    if ($request->hasHeader($header)) {
                        $span->setAttribute(
                            sprintf('%s.%s', TraceAttributes::HTTP_REQUEST_HEADER, strtolower($header)),
                            $request->getHeader($header)
                        );
                    }
                }

                Context::storage()->attach($context);

                return [$request];
            },
            post: static function (Transaction $transaction, array $params, PromiseInterface $promise): PromiseInterface {
                $scope = Context::storage()->scope();
                $scope?->detach();

                if (!$scope) {
                    return $promise;
                }

                $span = Span::fromContext($scope->context());

                if (!$span->isRecording()) {
                    return $promise;
                }

                return $promise->then(
                    onFulfilled: function (ResponseInterface $response) use ($span) {
                        // https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span
                        $span
                            ->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode())
                            ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());

                        if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 600) {
                            $span
                                ->setStatus(StatusCode::STATUS_ERROR)
                                ->setAttribute(TraceAttributes::ERROR_TYPE, (string) $response->getStatusCode());
                        }

                        foreach (explode(',', $_ENV[self::ENV_HTTP_RESPONSE_HEADERS] ?? '') as $header) {
                            if ($response->hasHeader($header)) {
                                $span->setAttribute(
                                    sprintf('%s.%s', TraceAttributes::HTTP_RESPONSE_HEADER, strtolower($header)),
                                    $response->getHeader($header)
                                );
                            }
                        }

                        $span->end();

                        return $response;
                    },
                    onRejected: function (Throwable $t) use ($span) {
                        $span->recordException($t);
                        if (is_a($t, ResponseException::class)) {
                            // https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span
                            $span
                                ->setStatus(StatusCode::STATUS_ERROR)
                                ->setAttribute(TraceAttributes::ERROR_TYPE, (string) $t->getCode())
                                ->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $t->getCode())
                                ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $t->getResponse()->getProtocolVersion());

                            foreach (explode(',', $_ENV[self::ENV_HTTP_RESPONSE_HEADERS] ?? '') as $header) {
                                if ($t->getResponse()->hasHeader($header)) {
                                    $span->setAttribute(
                                        sprintf('%s.%s', TraceAttributes::HTTP_RESPONSE_HEADER, strtolower($header)),
                                        $t->getResponse()->getHeader($header)
                                    );
                                }
                            }
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

    private static function canonizeMethod(string $method): ?string
    {
        // RFC9110, RFC5789
        $knownMethods = [
            TraceAttributeValues::HTTP_REQUEST_METHOD_GET,
            TraceAttributeValues::HTTP_REQUEST_METHOD_HEAD,
            TraceAttributeValues::HTTP_REQUEST_METHOD_POST,
            TraceAttributeValues::HTTP_REQUEST_METHOD_PUT,
            TraceAttributeValues::HTTP_REQUEST_METHOD_DELETE,
            TraceAttributeValues::HTTP_REQUEST_METHOD_CONNECT,
            TraceAttributeValues::HTTP_REQUEST_METHOD_OPTIONS,
            TraceAttributeValues::HTTP_REQUEST_METHOD_TRACE,
            TraceAttributeValues::HTTP_REQUEST_METHOD_PATCH,
        ];

        $overrideMethods = $_ENV[self::ENV_HTTP_KNOWN_METHODS] ?? '';
        if (!empty($overrideMethods)) {
            $knownMethods = explode(',', $overrideMethods);
        }

        if (in_array($method, $knownMethods)) {
            return $method;
        }

        return null;
    }

    private static function sanitizeUrl(UriInterface $uri): string
    {
        $userInfo = $uri->getUserInfo();
        if (str_contains($userInfo, ':')) {
            $uri = $uri->withUserInfo(self::URL_REDACTION, self::URL_REDACTION);
        } elseif ($userInfo !== '') {
            $uri = $uri->withUserInfo(self::URL_REDACTION);
        }

        $queryString = $uri->getQuery();
        // http_build_query(parse_str()) is not idempotent, so using Guzzle’s Query class for now
        if ($queryString !== '') {
            $queryParameters = Query::parse($queryString);
            $queryParameters = array_merge(
                $queryParameters,
                array_intersect_key(
                    array_fill_keys(
                        self::URL_QUERY_REDACT_KEYS,
                        self::URL_REDACTION
                    ),
                    $queryParameters
                )
            );
            $uri = $uri->withQuery(Query::build($queryParameters));
        }

        return (string) $uri;
    }
}

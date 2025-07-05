<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\ReactPHP;

use Composer\InstalledVersions;
use GuzzleHttp\Psr7\Query;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Common\Time\ClockInterface;
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
     * The environment variable which adds to the URL query parameter keys to redact the values for.
     * This supports a comma-separated list of case-sensitive query parameter keys.
     *
     * Note that this is not currently defined in OTel SemConv, and therefore subject to change.
     *
     * @see https://github.com/open-telemetry/semantic-conventions/issues/877
     */
    private const ENV_URL_SANITIZE_FIELD_NAMES = 'OTEL_PHP_INSTRUMENTATION_URL_SANITIZE_FIELD_NAMES';
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
        /** @var \OpenTelemetry\API\Metrics\HistogramInterface|null */
        static $histogram;

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

                /** @var array{'http.request.method':non-empty-string|null,'server.address':non-empty-string,'server.port':int} $requestMeta */
                $requestMeta = [
                    'http.request.method' => self::canonizeMethod($request->getMethod()),
                    'server.address' => $request->getUri()->getHost(),
                    'server.port' => $request->getUri()->getPort() ?? ($request->getUri()->getScheme() === 'https' ? 443 : 80),
                ];

                $spanBuilder = $instrumentation
                    ->tracer()
                    // https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span
                    ->spanBuilder($requestMeta['http.request.method'] ?? self::HTTP_REQUEST_METHOD_HTTP)
                    ->setParent($parentContext)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $requestMeta['http.request.method'] ?? TraceAttributeValues::HTTP_REQUEST_METHOD_OTHER)
                    ->setAttribute(TraceAttributes::SERVER_ADDRESS, $requestMeta['server.address'])
                    ->setAttribute(TraceAttributes::SERVER_PORT, $requestMeta['server.port'])
                    ->setAttribute(TraceAttributes::URL_FULL, self::sanitizeUrl($request->getUri()))
                    // https://opentelemetry.io/docs/specs/semconv/code/
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function));

                // https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span
                if ($requestMeta['http.request.method'] === null) {
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

                $requestStart = Clock::getDefault()->now();
                $span = $spanBuilder->setStartTimestamp($requestStart)->startSpan();
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

                $scope = Context::storage()->attach($context);
                $scope->offsetSet('requestMeta', $requestMeta);
                $scope->offsetSet('requestStart', $requestStart);

                return [$request];
            },
            post: static function (Transaction $transaction, array $params, PromiseInterface $promise) use (&$histogram, $instrumentation): PromiseInterface {
                $scope = Context::storage()->scope();
                $scope?->detach();

                if (!$scope) {
                    return $promise;
                }

                $span = Span::fromContext($scope->context());

                //https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#http-client
                $histogram ??= $instrumentation->meter()->createHistogram(
                    'http.client.request.duration',
                    's',
                    'Duration of HTTP client requests.',
                    ['ExplicitBucketBoundaries' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10]]
                );

                if (!$span->isRecording() && !$histogram->isEnabled()) {
                    return $promise;
                }

                /** @var array{'http.request.method':non-empty-string|null,'server.address':non-empty-string,'server.port':int} $requestMeta */
                $requestMeta = $scope->offsetGet('requestMeta');
                $requestMeta['http.request.method'] ??= '_OTHER';
                /** @var int $requestStart */
                $requestStart = $scope->offsetGet('requestStart');

                return $promise->then(
                    onFulfilled: function (ResponseInterface $response) use ($histogram, $requestMeta, $requestStart, $span) {
                        $requestEnd = Clock::getDefault()->now();
                        /** @var array{'http.response.status_code':int,'network.protocol.version':non-empty-string,'error.type'?:non-empty-string} $responseMeta */
                        $responseMeta = [
                            'http.response.status_code' => $response->getStatusCode(),
                            'network.protocol.version' => $response->getProtocolVersion(),
                        ];
                        // https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span
                        $span
                            ->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $responseMeta['http.response.status_code'])
                            ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $responseMeta['network.protocol.version']);

                        if ($responseMeta['http.response.status_code'] >= 400 && $responseMeta['http.response.status_code'] < 600) {
                            $span
                                ->setStatus(StatusCode::STATUS_ERROR)
                                ->setAttribute(TraceAttributes::ERROR_TYPE, (string) $responseMeta['http.response.status_code']);
                            $responseMeta['error.type'] = (string) $responseMeta['http.response.status_code'];
                        }

                        foreach (explode(',', $_ENV[self::ENV_HTTP_RESPONSE_HEADERS] ?? '') as $header) {
                            if ($response->hasHeader($header)) {
                                $span->setAttribute(
                                    sprintf('%s.%s', TraceAttributes::HTTP_RESPONSE_HEADER, strtolower($header)),
                                    $response->getHeader($header)
                                );
                            }
                        }

                        $span->end($requestEnd);

                        $histogram->record(
                            (float) (($requestEnd - $requestStart) / ClockInterface::NANOS_PER_SECOND),
                            array_merge($requestMeta, $responseMeta)
                        );

                        return $response;
                    },
                    onRejected: function (Throwable $t) use ($histogram, $requestMeta, $requestStart, $span) {
                        $requestEnd = Clock::getDefault()->now();
                        $span->recordException($t);
                        if (is_a($t, ResponseException::class)) {
                            /** @var array{'http.response.status_code':int,'network.protocol.version':non-empty-string,'error.type':non-empty-string} $responseMeta */
                            $responseMeta = [
                                'error.type' => (string) $t->getCode(),
                                'http.response.status_code' => $t->getCode(),
                                'network.protocol.version' => $t->getResponse()->getProtocolVersion(),
                            ];
                            // https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span
                            $span
                                ->setStatus(StatusCode::STATUS_ERROR)
                                ->setAttribute(TraceAttributes::ERROR_TYPE, $responseMeta['error.type'])
                                ->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $responseMeta['http.response.status_code'])
                                ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $responseMeta['network.protocol.version']);

                            foreach (explode(',', $_ENV[self::ENV_HTTP_RESPONSE_HEADERS] ?? '') as $header) {
                                if ($t->getResponse()->hasHeader($header)) {
                                    $span->setAttribute(
                                        sprintf('%s.%s', TraceAttributes::HTTP_RESPONSE_HEADER, strtolower($header)),
                                        $t->getResponse()->getHeader($header)
                                    );
                                }
                            }
                        } else {
                            /** @var array{'error.type':non-empty-string} $responseMeta */
                            $responseMeta = [
                                'error.type' => $t::class,
                            ];
                            $span
                                ->setStatus(StatusCode::STATUS_ERROR, $t->getMessage())
                                ->setAttribute(TraceAttributes::ERROR_TYPE, $responseMeta['error.type']);
                        }

                        $span->end($requestEnd);

                        $histogram->record(
                            (float) (($requestEnd - $requestStart) / ClockInterface::NANOS_PER_SECOND),
                            array_merge($requestMeta, $responseMeta)
                        );

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

        $sanitizeFields = self::URL_QUERY_REDACT_KEYS;
        $customFields = $_ENV[self::ENV_URL_SANITIZE_FIELD_NAMES] ?? '';
        if ($customFields !== '') {
            $sanitizeFields = array_merge($sanitizeFields, explode(',', $customFields));
        }

        $queryString = $uri->getQuery();
        // http_build_query(parse_str()) is not idempotent, so using Guzzle’s Query class for now
        if ($queryString !== '') {
            $queryParameters = Query::parse($queryString);
            $queryParameters = array_merge(
                $queryParameters,
                array_intersect_key(
                    array_fill_keys(
                        $sanitizeFields,
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

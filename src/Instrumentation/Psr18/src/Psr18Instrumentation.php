<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr18;

use function get_cfg_var;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use OpenTelemetry\SemConv\Version;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use function sprintf;
use function strtolower;
use Throwable;

/** @psalm-suppress UnusedClass */
class Psr18Instrumentation
{
    /** @psalm-suppress ArgumentTypeCoercion */
    public const NAME = 'psr18';

    private const CAPTURE_REQUEST_HEADERS_LEGACY_CFG_OPT_NAME = 'OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS';
    private const CAPTURE_REQUEST_HEADERS_CFG_OPT_NAME = 'OTEL_INSTRUMENTATION_HTTP_CLIENT_CAPTURE_REQUEST_HEADERS';
    private const CAPTURE_RESPONSE_HEADERS_LEGACY_CFG_OPT_NAME = 'OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS';
    private const CAPTURE_RESPONSE_HEADERS_CFG_OPT_NAME = 'OTEL_INSTRUMENTATION_HTTP_CLIENT_CAPTURE_RESPONSE_HEADERS';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.psr18',
            schemaUrl: Version::VERSION_1_38_0->url(),
        );

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            ClientInterface::class,
            'sendRequest',
            pre: static function (ClientInterface $client, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation): ?array {
                $request = $params[0] ?? null;
                if (!$request instanceof RequestInterface) {
                    Context::storage()->attach(Context::getCurrent());

                    return null;
                }

                $propagator = Globals::propagator();
                $parentContext = Context::getCurrent();

                /** @psalm-suppress ArgumentTypeCoercion */
                $spanBuilder = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('%s', $request->getMethod()))
                    ->setParent($parentContext)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(UrlAttributes::URL_FULL, (string) $request->getUri())
                    ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                    ->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                    ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                    ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                    ->setAttribute(ServerAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                    ->setAttribute(ServerAttributes::SERVER_PORT, $request->getUri()->getPort())
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                ;

                foreach ($propagator->fields() as $field) {
                    $request = $request->withoutHeader($field);
                }
                foreach (self::getRequestHeadersToCapture() as $header) {
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
            post: static function (ClientInterface $client, array $params, ?ResponseInterface $response, ?Throwable $exception): void {
                $scope = Context::storage()->scope();
                $scope?->detach();

                //@todo do we need the second part of this 'or'?
                if (!$scope || $scope->context() === Context::getCurrent()) {
                    return;
                }

                $span = Span::fromContext($scope->context());

                if ($response) {
                    $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                    $span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, $response->getHeaderLine('Content-Length'));

                    foreach (self::getResponseHeadersToCapture() as $header) {
                        if ($response->hasHeader($header)) {
                            /** @psalm-suppress ArgumentTypeCoercion */
                            $span->setAttribute(sprintf('http.response.header.%s', strtolower($header)), $response->getHeader($header));
                        }
                    }
                    if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 600) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                }
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            },
        );
    }

    private static function getRequestHeadersToCapture(): array
    {
        if (
            class_exists(Configuration::class)
            &&
            (
                (count($values = Configuration::getList(self::CAPTURE_REQUEST_HEADERS_LEGACY_CFG_OPT_NAME, [])) > 0)
                ||
                (count($values = Configuration::getList(self::CAPTURE_REQUEST_HEADERS_CFG_OPT_NAME, [])) > 0)
            )
        ) {
            return $values;
        }

        return (array) (get_cfg_var('otel.instrumentation.http.request_headers') ?: []);
    }

    private static function getResponseHeadersToCapture(): array
    {
        if (
            class_exists(Configuration::class)
            &&
            (
                (count($values = Configuration::getList(self::CAPTURE_RESPONSE_HEADERS_LEGACY_CFG_OPT_NAME, [])) > 0)
                ||
                (count($values = Configuration::getList(self::CAPTURE_RESPONSE_HEADERS_CFG_OPT_NAME, [])) > 0)
            )
        ) {
            return $values;
        }

        return (array) (get_cfg_var('otel.instrumentation.http.response_headers') ?: []);
    }
}

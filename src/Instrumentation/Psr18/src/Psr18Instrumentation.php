<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr18;

use function get_cfg_var;
use Nevay\SPI\ServiceProviderDependency\ExtensionDependency;
use OpenTelemetry\API\Configuration\ConfigProperties;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context as InstrumentationContext;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
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
#[ExtensionDependency('opentelemetry', '^1.0')]
class Psr18Instrumentation implements Instrumentation
{
    /** @psalm-suppress ArgumentTypeCoercion */
    public const NAME = 'psr18';

    private const CAPTURE_REQUEST_HEADERS_LEGACY_CFG_OPT_NAME = 'OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS';
    private const CAPTURE_REQUEST_HEADERS_CFG_OPT_NAME = 'OTEL_INSTRUMENTATION_HTTP_CLIENT_CAPTURE_REQUEST_HEADERS';
    private const CAPTURE_RESPONSE_HEADERS_LEGACY_CFG_OPT_NAME = 'OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS';
    private const CAPTURE_RESPONSE_HEADERS_CFG_OPT_NAME = 'OTEL_INSTRUMENTATION_HTTP_CLIENT_CAPTURE_RESPONSE_HEADERS';

    public function register(HookManagerInterface $hookManager, ConfigProperties $configuration, InstrumentationContext $context): void
    {
        $tracer = $context->tracerProvider->getTracer(
            'io.opentelemetry.contrib.php.psr18',
            schemaUrl: Version::VERSION_1_38_0->url(),
        );

        /** @psalm-suppress ArgumentTypeCoercion */
        $hookManager->hook(
            ClientInterface::class,
            'sendRequest',
            preHook: static function (ClientInterface $client, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($context, $tracer): ?array {
                $request = $params[0] ?? null;
                if (!$request instanceof RequestInterface) {
                    Context::storage()->attach(Context::getCurrent());

                    return null;
                }

                $parentContext = Context::getCurrent();

                $spanBuilder = $tracer
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

                foreach ($context->propagator->fields() as $field) {
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
                $inContext = $span->storeInContext($parentContext);
                $context->propagator->inject($request, HeadersPropagator::instance(), $inContext);

                Context::storage()->attach($inContext);

                return [$request];
            },
            postHook: static function (ClientInterface $client, array $params, ?ResponseInterface $response, ?Throwable $exception): void {
                $scope = Context::storage()->scope();
                $scope?->detach();

                //@todo do we need the second part of this 'or'?
                if (!$scope || $scope->context() === Context::getCurrent()) {
                    return;
                }

                $span = Span::fromContext($scope->context());

                if ($response instanceof ResponseInterface) {
                    $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                    $span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, $response->getHeaderLine('Content-Length'));

                    foreach (self::getResponseHeadersToCapture() as $header) {
                        if ($response->hasHeader($header)) {
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

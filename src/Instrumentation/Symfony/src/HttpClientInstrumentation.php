<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @phan-file-suppress PhanTypeInvalidCallableArraySize
 * @psalm-suppress UnusedClass
 */
final class HttpClientInstrumentation
{
    /**
     * These clients are not supported by this instrumentation, because
     * they are synchronous and do not support the on_progress option.
     */
    const SYNCHRONOUS_CLIENTS = [
        /** @psalm-suppress UndefinedClass */
        'ApiPlatform\Symfony\Bundle\Test\Client',
    ];

    public static function supportsProgress(string $class): bool
    {
        return false === in_array($class, self::SYNCHRONOUS_CLIENTS);
    }

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.symfony_http',
            null,
            'https://opentelemetry.io/schemas/1.30.0',
        );

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            HttpClientInterface::class,
            'request',
            pre: static function (
                HttpClientInterface $client,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('%s', $params[0]))
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::PEER_SERVICE, parse_url((string) $params[1])['host'] ?? null)
                    ->setAttribute(TraceAttributes::URL_FULL, (string) $params[1])
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $params[0])
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);

                $propagator = Globals::propagator();
                $parent = Context::getCurrent();

                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $requestOptions = $params[2] ?? [];

                if (!isset($requestOptions['headers'])) {
                    $requestOptions['headers'] = [];
                }

                /** @psalm-suppress UndefinedClass */
                if (false === self::supportsProgress($class)) {
                    $context = $span->storeInContext($parent);
                    $propagator->inject($requestOptions['headers'], ArrayAccessGetterSetter::getInstance(), $context);

                    Context::storage()->attach($context);

                    return $params;
                }

                $previousOnProgress = $requestOptions['on_progress'] ?? null;

                //As Response are lazy we end span when status code was received
                $requestOptions['on_progress'] = static function (int $dlNow, int $dlSize, array $info) use (
                    $previousOnProgress,
                    $span
                ): void {
                    if (null !== $previousOnProgress) {
                        $previousOnProgress($dlNow, $dlSize, $info);
                    }

                    $statusCode = $info['http_code'];

                    if (0 !== $statusCode && null !== $statusCode && $span->isRecording()) {
                        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);

                        if ($statusCode >= 400 && $statusCode < 600) {
                            $span->setStatus(StatusCode::STATUS_ERROR);
                        }

                        $span->end();
                    }
                };

                $context = $span->storeInContext($parent);
                $propagator->inject($requestOptions['headers'], ArrayAccessGetterSetter::getInstance(), $context);

                Context::storage()->attach($context);
                $params[2] = $requestOptions;

                return $params;
            },
            post: static function (
                HttpClientInterface $client,
                array $params,
                ?ResponseInterface $response,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());

                if (null !== $exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                    $span->end();

                    return;
                }

                if ($response !== null && false === self::supportsProgress(get_class($client))) {
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());

                    if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 600) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                }

                // As most Response are lazy we end span after response is received,
                // it's added in on_progress callback, see line 69
            },
        );
    }
}

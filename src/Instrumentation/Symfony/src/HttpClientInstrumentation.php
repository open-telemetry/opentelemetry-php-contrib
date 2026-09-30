<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
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

    private static function requestKey(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey('symfony-http-client.request');
    }

    private static function forwardedKey(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey('symfony-http-client.forwarded');
    }

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.symfony_http',
            null,
            Version::VERSION_1_38_0->url(),
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
                $parent = Context::getCurrent();
                $request = sprintf('%s %s', $params[0], (string) $params[1]);

                if ($parent->get(self::requestKey()) === $request) {
                    Context::storage()->attach($parent->with(self::forwardedKey(), true));

                    return $params;
                }

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('%s', $params[0]))
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::PEER_SERVICE, parse_url((string) $params[1])['host'] ?? null)
                    ->setAttribute(UrlAttributes::URL_FULL, (string) $params[1])
                    ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $params[0])
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);

                $propagator = Globals::propagator();

                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $requestOptions = $params[2] ?? [];

                if (!isset($requestOptions['headers'])) {
                    $requestOptions['headers'] = [];
                }

                /** @psalm-suppress UndefinedClass */
                if (false === self::supportsProgress($class)) {
                    $context = self::ownerContext($span->storeInContext($parent), $request);
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
                        $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);

                        if ($statusCode >= 400 && $statusCode < 600) {
                            $span->setStatus(StatusCode::STATUS_ERROR);
                        }

                        $span->end();
                    }
                };

                $context = self::ownerContext($span->storeInContext($parent), $request);
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

                if (true === $scope->context()->get(self::forwardedKey())) {
                    return;
                }

                $span = Span::fromContext($scope->context());

                if (null !== $exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                    $span->end();

                    return;
                }

                if ($response !== null && false === self::supportsProgress(get_class($client))) {
                    $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());

                    if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 600) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                }

                // As most Response are lazy we end span after response is received,
                // it's added in on_progress callback, see line 69
            },
        );
    }

    private static function ownerContext(ContextInterface $context, string $request): ContextInterface
    {
        return $context
            ->with(self::requestKey(), $request)
            ->with(self::forwardedKey(), false);
    }
}
